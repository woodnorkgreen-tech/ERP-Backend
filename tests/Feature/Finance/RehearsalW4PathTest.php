<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Report 67 Gate 1: the corrected W4 rehearsal path, call for call.
 *
 * The rehearsal used to pay a received-but-not-billed GRN accrual with a payment
 * voucher; Stream E refuses that (Report 63 §6). Its W4 step now captures a
 * supplier invoice on credit through the Cost Collector, has a second person
 * verify it, and pays exactly that liability. This runs those same API calls
 * against the current code, so the rehearsal step is known to be valid before
 * the rehearsal checkout is refreshed.
 */
class RehearsalW4PathTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_supplier_invoice_on_credit_is_paid_by_a_voucher_end_to_end(): void
    {
        foreach ([FinanceDimensionSeeder::class, AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, PaymentSourceSeeder::class, ExpenseCodeSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
        foreach ([Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_SPEND_VOUCHERS_CREATE,
            Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_SPEND_VOUCHERS_POST] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $person = function (array $permissions): User {
            $user = User::factory()->create(['is_active' => true]);
            $user->givePermissionTo($permissions);

            return $user;
        };
        $capturer = $person([Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ]);
        $verifier = $person([Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_COSTS_READ]);
        $accounts = $person([Permissions::FINANCE_SPEND_VOUCHERS_CREATE, Permissions::FINANCE_SPEND_VOUCHERS_READ]);
        $approver = $person([Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_SPEND_VOUCHERS_READ]);
        $poster = $person([Permissions::FINANCE_SPEND_VOUCHERS_POST, Permissions::FINANCE_SPEND_VOUCHERS_READ]);
        $supplier = Supplier::create([
            'supplier_name' => 'Rehearsal Supplier Ltd', 'contact_person' => 'Accounts', 'phone' => '0700000099',
            'email' => 'rehearsal-supplier@example.test', 'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $capturer->id, 'kra_pin' => 'P059999999A',
        ]);

        // Capture (Super Admin A in the rehearsal), with the invoice uploaded first as the
        // capture form does, and verify (Super Admin B).
        Storage::fake('public');
        $evidence = $this->actingAs($capturer, 'sanctum')->post('/api/costs/evidence', [
            'key' => 'receipt', 'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data');
        $costId = $this->actingAs($capturer, 'sanctum')->postJson('/api/costs', [
            'evidence' => [['key' => $evidence['key'], 'path' => $evidence['path']]],
            'expense_code' => 'OE-OFF-001', 'amount' => 100, 'description' => 'Rehearsal supplier invoice on credit',
            'funding_mode' => 'unpaid_invoice', 'payee_type' => 'SUPPLIER', 'payee_id' => $supplier->id, 'payee_name' => $supplier->supplier_name,
        ])->assertCreated()->json('data.id');
        $this->actingAs($verifier, 'sanctum')->postJson("/api/costs/verification/{$costId}/verify")->assertOk();
        $this->assertNotNull(CostLine::findOrFail($costId)->journal_entry_id, 'Verification did not post the liability.');

        // Eligible, then paid exactly (Accounts creates, another approves, a third posts).
        $eligible = $this->actingAs($accounts, 'sanctum')->getJson('/api/finance/spend-vouchers/eligible-liabilities')->assertOk()->json('data');
        $this->assertTrue(collect($eligible)->contains('id', $costId));
        $voucherId = $this->actingAs($accounts, 'sanctum')->postJson('/api/finance/spend-vouchers', [
            'type' => 'payment', 'payee_name' => 'Rehearsal Payee', 'total_amount' => 100.0, 'payment_method' => 'bank_transfer',
            'payment_source_id' => PaymentSource::where('is_active', true)->where('can_make_payment', true)->where('type', 'bank')->whereNotNull('gl_account_id')->orderBy('id')->value('id'),
            'allocations' => [['cost_line_id' => $costId, 'amount' => 100.0]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($approver, 'sanctum')->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")->assertOk();
        $this->actingAs($poster, 'sanctum')->postJson("/api/finance/spend-vouchers/{$voucherId}/post")->assertOk();

        $this->assertSame(1, Payment::where('spend_voucher_id', $voucherId)->count());
        $this->assertSame(1, JournalEntry::where('spend_voucher_id', $voucherId)->count());
        $this->assertNotNull(CostLine::findOrFail($costId)->settled_by_payment_id, 'The liability was not settled.');
    }
}
