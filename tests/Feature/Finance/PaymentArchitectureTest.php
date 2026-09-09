<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\Support\DocumentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * One payment function, whatever account the money leaves.
 *
 * The rules this pins down were each broken in a different place: the paying
 * account decided the payment method (so a cheque was unrecordable), the payment
 * had no number of its own (so the payee's M-Pesa code was its only identifier),
 * and the account list was read-only behind four separate permissions.
 */
class PaymentArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;
    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::FINANCE_PAYMENT_SOURCES_MANAGE,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');

        $this->clerk = User::factory()->create(['is_active' => true]);
        $this->clerk->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);

        // A direct payment is maker-checker by design, so these tests have to
        // walk the same two steps a person does.
        $this->checker = User::factory()->create(['is_active' => true]);
        $this->checker->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);

        PettyCashTopUp::create([
            'amount' => 500000, 'payment_method' => 'cash',
            'date_topped_up' => now()->toDateString(), 'created_by' => $this->clerk->id,
        ]);
        PettyCashBalance::current()->update(['current_balance' => 500000]);
    }

    /**
     * The float pays out in cash AND by M-Pesa; a bank pays by transfer, cheque,
     * RTGS or EFT. Deriving one from the other lost the difference — and both
     * live spend vouchers are petty cash paid out over M-Pesa.
     */
    public function test_the_paying_account_does_not_dictate_the_payment_method(): void
    {
        foreach ([
            ['PC-MAIN', 'mpesa', 'QWE72X12AB'],
            ['PC-MAIN', 'cash', null],
            ['BANK-MAIN', 'cheque', 'CHQ-004411'],
            ['BANK-MAIN', 'rtgs', 'RTGS-99120'],
            ['BANK-MAIN', 'eft', 'EFT-55010'],
        ] as [$sourceCode, $method, $reference]) {
            $payment = $this->pay($sourceCode, $method, $reference);

            $this->assertSame($method, $payment->payment_method,
                "A {$method} payment from {$sourceCode} must record the method it was given.");
            $this->assertSame(
                PaymentSource::where('code', $sourceCode)->value('id'),
                $payment->payment_source_id,
            );
        }
    }

    /** A method that is not on the canonical list is refused, banks included. */
    public function test_a_bank_name_is_not_a_payment_method(): void
    {
        foreach (['equity', 'ncba', 'kcb', 'stanbic', 'family'] as $bank) {
            $this->submit('BANK-MAIN', $bank, 'REF-001')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_method']);
        }
    }

    /**
     * The ERP's identifier for the payment, distinct from the payee's reference.
     */
    public function test_every_payment_is_issued_its_own_document_number(): void
    {
        $first = $this->pay('PC-MAIN', 'cash');
        $second = $this->pay('BANK-MAIN', 'rtgs', 'RTGS-1');

        $year = now()->year;
        $this->assertSame("PAY-{$year}-0001", $first->payment_no);
        $this->assertSame("PAY-{$year}-0002", $second->payment_no,
            'The number counts payments, not payments from one account.');

        // The external reference stays a separate field, holding the payee's.
        $this->assertNull($first->external_reference);
        $this->assertSame('RTGS-1', $second->external_reference);
    }

    /** A series is never issued the same number twice. */
    public function test_a_document_number_is_issued_once(): void
    {
        $issued = [];
        for ($i = 0; $i < 5; $i++) {
            $issued[] = DB::transaction(fn () => DocumentNumber::next('TEST', '2026'));
        }

        $this->assertSame($issued, array_unique($issued));
        $this->assertSame('TEST-2026-0001', $issued[0]);
        $this->assertSame('TEST-2026-0005', $issued[4]);
    }

    /**
     * Same expense, same posting engine, different credit account. This is the
     * whole reason a payment source carries a GL account.
     */
    public function test_the_credit_leg_follows_the_paying_account(): void
    {
        foreach ([['PC-MAIN', '1030'], ['BANK-MAIN', '1010'], ['MPESA', '1040']] as [$sourceCode, $accountCode]) {
            $this->assertSame(
                ChartOfAccount::where('code', $accountCode)->value('id'),
                PaymentSource::where('code', $sourceCode)->value('gl_account_id'),
                "A payment from {$sourceCode} must credit {$accountCode}.",
            );
        }
    }

    /** Paying from a bank must not touch the float. */
    public function test_a_bank_payment_leaves_the_float_alone(): void
    {
        $opening = (string) PettyCashBalance::current()->current_balance;

        $this->pay('BANK-MAIN', 'bank_transfer', 'FT-001');

        $this->assertSame($opening, (string) PettyCashBalance::current()->fresh()->current_balance);

        $this->pay('PC-MAIN', 'cash');

        $this->assertNotSame($opening, (string) PettyCashBalance::current()->fresh()->current_balance,
            'A float payment still moves the float.');
    }

    // ---------------------------------------------------------------- master

    public function test_anyone_may_read_the_paying_accounts_but_only_finance_opens_one(): void
    {
        $this->actingAs($this->clerk, 'sanctum')
            ->getJson('/api/finance/payment-sources')
            ->assertOk()
            ->assertJsonPath('meta.can_manage', false);

        $this->actingAs($this->clerk, 'sanctum')
            ->postJson('/api/finance/payment-sources', $this->accountPayload())
            ->assertForbidden();
    }

    public function test_finance_can_open_a_new_paying_account_without_a_developer(): void
    {
        $finance = User::factory()->create(['is_active' => true]);
        $finance->givePermissionTo(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE);

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/finance/payment-sources', $this->accountPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'BANK-COOP');

        $this->assertDatabaseHas('payment_sources', ['code' => 'BANK-COOP', 'is_active' => true]);
    }

    /**
     * An account with no ledger account cannot say what a payment through it
     * credits, so the posting would silently fall back to Accounts Payable.
     */
    public function test_a_paying_account_must_name_a_ledger_account(): void
    {
        $finance = User::factory()->create(['is_active' => true]);
        $finance->givePermissionTo(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE);

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/finance/payment-sources', ['gl_account_id' => null] + $this->accountPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gl_account_id']);
    }

    /** The banks that used to be payment methods survived as inactive accounts. */
    public function test_the_retired_bank_methods_became_accounts(): void
    {
        foreach (['BANK-STANBIC', 'BANK-KCB', 'BANK-FAMILY'] as $code) {
            $this->assertDatabaseHas('payment_sources', ['code' => $code, 'type' => 'bank', 'is_active' => false]);
        }

        $listed = collect(
            $this->actingAs($this->clerk, 'sanctum')->getJson('/api/finance/payment-sources')->json('data')
        )->pluck('code');

        $this->assertNotContains('BANK-STANBIC', $listed,
            'An account Finance has not confirmed must not be offered to pay from.');
    }

    // --------------------------------------------------------------- helpers

    private function accountPayload(): array
    {
        return [
            'code' => 'BANK-COOP',
            'name' => 'Co-operative Bank – Projects',
            'type' => 'bank',
            'gl_account_id' => ChartOfAccount::where('code', '1010')->value('id'),
            'currency' => 'KES',
        ];
    }

    /** Raise a direct payment and have it approved, returning the payment. */
    private function pay(string $sourceCode, string $method, ?string $reference = null): Payment
    {
        $requestId = $this->submit($sourceCode, $method, $reference)
            ->assertStatus(202)
            ->json('data.id');

        $this->actingAs($this->checker, 'sanctum')
            ->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve")
            ->assertSuccessful();

        return Payment::latest('id')->firstOrFail();
    }

    private function submit(string $sourceCode, string $method, ?string $reference = null)
    {
        return $this->actingAs($this->clerk, 'sanctum')->postJson('/api/finance/petty-cash/disbursements', [
            'idempotency_key' => (string) Str::uuid(),
            'payee_name' => 'Kamau Transporters',
            'expense_code_id' => DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id'),
            'payment_source_id' => PaymentSource::where('code', $sourceCode)->value('id'),
            'payment_method' => $method,
            'external_reference' => $reference,
            'amount' => 1500,
            'transaction_cost' => 0,
            'description' => 'Site transport',
            'direct_payment_reason' => 'Recorded directly against an approved operational need.',
            'date_disbursed' => now()->toDateString(),
            'receipt_type' => 'none',
            'tax_amount' => 0,
        ]);
    }
}
