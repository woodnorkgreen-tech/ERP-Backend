<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PettyCashSurrenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $financeUser;
    private int $departmentId;
    private int $topUpId;
    private int $expenseCodeId;
    private int $paymentSourceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);

        \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web');
        $this->financeUser = User::factory()->create(['is_active' => true]);
        $this->financeUser->assignRole('Super Admin');

        $this->departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', '!=', 'not_allowed')->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->financeUser->id,
        ])->id;

        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    private function enquiry(string $jobNumber): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_full_float_advance_surrender_and_reconciliation_flow(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-099');

        $type = PettyCashRequisitionType::create([
            'code' => 'MAT-' . uniqid(),
            'name' => 'Site Materials',
            'default_expense_code_id' => $this->expenseCodeId,
            'is_active' => true,
        ]);

        // 1. Create requisition for KES 10,000 float
        $requisition = PettyCashRequisition::create([
            'requisition_number' => 'PCR-TEST-001',
            'user_id' => $this->user->id,
            'department_id' => $this->departmentId,
            'category' => 'Site Materials',
            'requisition_type_id' => $type->id,
            'purpose' => 'Buying timber and nails for site',
            'total_amount' => 10000.00,
            'status' => 'approved',
            'enquiry_id' => $enquiryId,
            'payee_name' => 'John Field Worker',
        ]);

        // 2. Producer commits the requisition (commitment on project)
        $producer = app(PettyCashCostProducer::class);
        $producer->commitFor($requisition);

        $commitment = CostLine::where('nature', CostLine::NATURE_COMMITTED)
            ->where('source_type', PettyCashRequisition::class)
            ->where('source_id', $requisition->id)
            ->first();
        $this->assertNotNull($commitment);
        $this->assertSame('10000.00', $commitment->net_amount);

        // 3. Finance disburses the KES 10,000 cash advance
        $this->actingAs($this->financeUser);

        $response = $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/disburse", [
            'idempotency_key' => (string) Str::uuid(),
            'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'payment_method' => 'cash',
            'amount' => 10000.00,
            'payee_name' => 'John Field Worker',
            'description' => 'Disbursing site float',
            'date_disbursed' => now()->toDateString(),
            'receipt_type' => 'none',
        ]);

        if ($response->status() !== 200) {
            dump($response->json());
        }
        $response->assertStatus(200);
        $requisition->refresh();
        $this->assertSame('disbursed', $requisition->status);

        // Verify Advance Journal Entry (Dr 1300 Staff Advance / Cr 1010 Cash Float)
        $this->assertNotNull($requisition->advance_journal_entry_id);
        $advanceJournal = $requisition->advanceJournalEntry;
        $this->assertSame('10000.00', $advanceJournal->total_debit);
        $this->assertSame('10000.00', $advanceJournal->total_credit);

        $debitLine = $advanceJournal->lines->where('entry_type', 'debit')->first();
        $advanceAccount = ChartOfAccount::where('code', '1300')->first();
        $this->assertSame($advanceAccount->id, $debitLine->account_id);

        // 4. Staff member submits Surrender:
        //    Receipt 1: Timber KES 7,000 (with ETR receipt and KES 965.52 VAT)
        //    Receipt 2: Transport boda KES 1,500 (non-ETR)
        //    Cash returned: KES 1,500 (Total accounted = 7,000 + 1,500 + 1,500 = 10,000)
        $this->actingAs($this->user);

        $surrenderResponse = $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/surrender", [
            'items' => [
                [
                    'expense_code_id' => $this->expenseCodeId,
                    'amount' => 7000.00,
                    'tax_amount' => 965.52,
                    'receipt_type' => 'etr',
                    'receipt_number' => 'INV-TIMBER-001',
                    'supplier_kra_pin' => 'P051234567Z',
                    'supplier_name' => 'Timber World Ltd',
                    'description' => 'Hardwood timber 2x4',
                ],
                [
                    'expense_code_id' => $this->expenseCodeId,
                    'amount' => 1500.00,
                    'tax_amount' => 0.00,
                    'receipt_type' => 'non_etr',
                    'supplier_name' => 'Boda Transport',
                    'description' => 'Site transport to location',
                ],
            ],
            'cash_returned_amount' => 1500.00,
            'surrender_notes' => 'Timber bought and balance returned to safe.',
        ]);

        $surrenderResponse->assertStatus(200);
        $requisition->refresh();
        $this->assertSame('surrender_pending', $requisition->status);
        $this->assertCount(2, $requisition->surrenderItems);
        $this->assertEquals('8500.00', $requisition->actual_spent_amount);
        $this->assertEquals('1500.00', $requisition->cash_returned_amount);

        // 5. Finance reconciles the surrender
        $this->actingAs($this->financeUser);

        $reconcileResponse = $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/reconcile");
        if ($reconcileResponse->status() !== 200) {
            dump($reconcileResponse->json());
        }
        $reconcileResponse->assertStatus(200);

        $requisition->refresh();
        $this->assertSame('surrendered', $requisition->status);
        $this->assertNotNull($requisition->surrender_reconciled_at);
        $this->assertNotNull($requisition->surrender_journal_entry_id);

        // 6. Verify Actual CostLines were created
        $actualCosts = CostLine::where('nature', CostLine::NATURE_ACTUAL)
            ->where('source_type', \App\Modules\Finance\PettyCash\Models\PettyCashSurrenderItem::class)
            ->get();
        $this->assertCount(2, $actualCosts);

        // Verify original commitment was released
        $commitment->refresh();
        $this->assertSame(CostLine::STATUS_REVERSED, $commitment->status);

        // 7. Verify Surrender Clearing Journal
        $surrenderJournal = $requisition->surrenderJournalEntry;
        $this->assertSame('10000.00', $surrenderJournal->total_debit);
        $this->assertSame('10000.00', $surrenderJournal->total_credit);

        // Debits include Expense + VAT Input (1330) + Cash returned (1010)
        // Credits include Staff Advance cleared (1300) = 10,000.00
        $creditLine = $surrenderJournal->lines->where('entry_type', 'credit')->first();
        $this->assertSame($advanceAccount->id, $creditLine->account_id);
        $this->assertSame('10000.00', $creditLine->amount);
    }
}
