<?php

namespace Tests\Feature\CostCollector;

use App\Events\PettyCashDisbursementPaid;
use App\Events\PettyCashDisbursementVoided;
use App\Listeners\RecordPettyCashCost;
use App\Listeners\ReversePettyCashCost;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The live wiring, as distinct from {@see PettyCashCostProducerTest} which covers
 * the producer's own rules. What matters here is that paying and voiding through
 * the real service reaches the cost ledger at all — that was the gap: the
 * producer worked and nothing called it outside a backfill command.
 */
class PettyCashCostListenerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $topUpId;
    private int $expenseCodeId;
    private int $paymentSourceId;
    private int $overheadExpenseCodeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceReferenceSeeder::class);
        $this->expenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', '!=', 'not_allowed')->value('id');
        $this->overheadExpenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->user->id,
        ])->id;

        // Creating the top-up model no longer moves the cached balance —
        // LedgerService is the only writer — so the float has to be stated for
        // the service's sufficiency check to pass.
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

    private function disbursement(array $overrides = []): PettyCashDisbursement
    {
        return PettyCashDisbursement::create(array_merge([
            'top_up_id' => $this->topUpId,
            'amount' => 4500.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'status' => 'active',
            'date_disbursed' => now()->subDays(3)->toDateString(),
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_paying_petty_cash_records_a_project_cost(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-004');
        $planned = CostLine::create([
            'ref' => 'CL-PLANNED-1',
            'project_enquiry_id' => $enquiryId,
            'job_number' => 'WNG-01-2026-004',
            'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '10000.00',
            'tax_amount' => '0.00',
            'net_amount' => '10000.00',
            'base_net_amount' => '10000.00',
            'details' => ['budget_category' => 'logistics'],
        ]);

        $result = app(PettyCashService::class)->createDisbursement([
            'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'top_up_id' => $this->topUpId,
            'amount' => 4500.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'job_number' => 'WNG-01-2026-004',
            'planned_cost_line_id' => $planned->id,
            'date_disbursed' => now()->toDateString(),
        ]);

        $this->assertTrue($result['success']);

        $line = CostLine::where('source_type', PettyCashDisbursement::class)
            ->where('source_id', $result['data']->id)
            ->first();

        $this->assertNotNull($line, 'Paying petty cash should have reached the cost ledger');
        $this->assertSame($enquiryId, $line->project_enquiry_id);
        $this->assertSame(CostLine::NATURE_ACTUAL, $line->nature);
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame($planned->id, $line->consumes_line_id);
        $this->assertSame($planned->id, $result['data']->planned_cost_line_id);
        $this->assertSame('logistics', $result['data']->budget_category);
        $this->assertNotNull($line->journal_entry_id);
        $this->assertNotNull($line->posted_at);
        $this->assertSame('5500.00', bcsub(
            (string) $planned->net_amount,
            (string) $planned->consumers()->counting()->sum('net_amount'),
            2,
        ));
    }

    public function test_replaying_a_create_key_does_not_debit_cash_twice(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'idempotency_key' => $key,
            'top_up_id' => $this->topUpId,
            'amount' => 4500.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Idempotent site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'date_disbursed' => now()->toDateString(),
        ];

        $service = app(PettyCashService::class);
        $first = $service->createDisbursement($payload);
        $replay = $service->createDisbursement($payload);

        $this->assertTrue($first['success']);
        $this->assertTrue($replay['success']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['data']->id, $replay['data']->id);
        $this->assertSame(1, PettyCashDisbursement::where('idempotency_key', $key)->count());
        $this->assertSame(1, DB::table('petty_cash_ledger_entries')
            ->where('reference_number', 'PCR-' . str_pad((string) $first['data']->id, 6, '0', STR_PAD_LEFT))
            ->where('type', 'debit')
            ->count());
    }

    public function test_transaction_fee_posts_as_a_separate_finance_cost(): void
    {
        $this->enquiry('WNG-01-2026-099');

        $result = app(PettyCashService::class)->createDisbursement([
            'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'top_up_id' => $this->topUpId,
            'amount' => 2000,
            'transaction_cost' => 35,
            'receiver' => 'Supplier',
            'description' => 'Project purchase with transfer fee',
            'job_number' => 'WNG-01-2026-099',
            'date_disbursed' => now()->toDateString(),
        ]);

        $this->assertTrue($result['success']);
        $lines = CostLine::where('source_type', PettyCashDisbursement::class)
            ->where('source_id', $result['data']->id)
            ->orderBy('source_ref')->get();

        $this->assertCount(2, $lines);
        $fee = $lines->firstWhere('source_ref', 'transaction-fee');
        $this->assertNotNull($fee);
        $this->assertSame('35.00', $fee->amount);
        $this->assertSame('OE-FIN-001', $fee->expenseCode->code);
        $this->assertSame('2035.00', DB::table('petty_cash_ledger_entries')
            ->where('reference_number', 'PCR-' . str_pad((string) $result['data']->id, 6, '0', STR_PAD_LEFT))
            ->value('amount'));
    }

    public function test_a_budget_line_from_another_project_is_rejected_before_payment(): void
    {
        $firstEnquiry = $this->enquiry('WNG-01-2026-010');
        $otherEnquiry = $this->enquiry('WNG-01-2026-011');
        $planned = CostLine::create([
            'ref' => 'CL-PLANNED-OTHER',
            'project_enquiry_id' => $otherEnquiry,
            'job_number' => 'WNG-01-2026-011',
            'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '10000.00', 'tax_amount' => '0.00',
            'net_amount' => '10000.00', 'base_net_amount' => '10000.00',
        ]);

        $result = app(PettyCashService::class)->createDisbursement([
            'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'amount' => 1000,
            'receiver' => 'Supplier',
            'account' => 'Transport',
            'description' => 'Wrong project line',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'project_enquiry_id' => $firstEnquiry,
            'job_number' => 'WNG-01-2026-010',
            'planned_cost_line_id' => $planned->id,
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('planned_cost_line_id', $result['errors']);
        $this->assertSame(0, PettyCashDisbursement::where('description', 'Wrong project line')->count());
    }

    /**
     * The idempotency the integration doc requires of every producer: a queue
     * retry must not charge the project twice.
     */
    public function test_handling_the_event_twice_posts_one_cost_line(): void
    {
        $this->enquiry('WNG-01-2026-004');
        $disbursement = $this->disbursement(['job_number' => 'WNG-01-2026-004']);

        $event = new PettyCashDisbursementPaid($disbursement->id);

        app(RecordPettyCashCost::class)->handle($event);
        app(RecordPettyCashCost::class)->handle($event);

        $this->assertSame(1, CostLine::where('source_type', PettyCashDisbursement::class)
            ->where('source_id', $disbursement->id)
            ->count());
    }

    public function test_voiding_a_paid_disbursement_reverses_its_cost(): void
    {
        $this->enquiry('WNG-01-2026-004');
        $disbursement = $this->disbursement([
            'job_number' => 'WNG-01-2026-004',
            'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'transaction_cost' => 25,
        ]);

        app(RecordPettyCashCost::class)->handle(new PettyCashDisbursementPaid($disbursement->id));

        $line = CostLine::where('source_id', $disbursement->id)->firstOrFail();
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);

        app(ReversePettyCashCost::class)->handle(
            new PettyCashDisbursementVoided($disbursement->id, $this->user->id, 'Paid twice'),
        );

        $this->assertSame(CostLine::STATUS_REVERSED, $line->fresh()->status);
        $this->assertSame(2, CostLine::where('source_id', $disbursement->id)->count());
        $this->assertSame(0, CostLine::where('source_id', $disbursement->id)
            ->where('status', '!=', CostLine::STATUS_REVERSED)->count());
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $line->journal_entry_id,
            'status' => 'posted',
        ]);
    }

    /** A payment that never became a cost has nothing to back out. */
    public function test_voiding_an_uncosted_disbursement_is_a_no_op(): void
    {
        $disbursement = $this->disbursement(['job_number' => null]);

        app(ReversePettyCashCost::class)->handle(
            new PettyCashDisbursementVoided($disbursement->id, $this->user->id, 'Keyed in error'),
        );

        $this->assertSame(0, CostLine::where('source_id', $disbursement->id)->count());
    }

    /**
     * The dispatch has to survive the service, not just exist on the listener —
     * this is the half that was missing entirely.
     */
    public function test_the_service_dispatches_on_pay_and_void(): void
    {
        Event::fake([PettyCashDisbursementPaid::class, PettyCashDisbursementVoided::class]);

        $service = app(PettyCashService::class);

        $result = $service->createDisbursement([
            'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'top_up_id' => $this->topUpId,
            'amount' => 1200.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'date_disbursed' => now()->toDateString(),
        ]);

        Event::assertDispatched(PettyCashDisbursementPaid::class);

        $service->voidDisbursement($result['data'], 'Duplicate');

        Event::assertDispatched(PettyCashDisbursementVoided::class);
    }

    /** An insufficient-balance rejection is not a payment, so nothing is costed. */
    public function test_a_rejected_disbursement_dispatches_nothing(): void
    {
        Event::fake([PettyCashDisbursementPaid::class]);

        $result = app(PettyCashService::class)->createDisbursement([
            'expense_code_id' => $this->overheadExpenseCodeId,
            'payment_source_id' => $this->paymentSourceId,
            'top_up_id' => $this->topUpId,
            'amount' => 99999999.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Too much',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'date_disbursed' => now()->toDateString(),
        ]);

        $this->assertFalse($result['success']);
        Event::assertNotDispatched(PettyCashDisbursementPaid::class);
    }
}
