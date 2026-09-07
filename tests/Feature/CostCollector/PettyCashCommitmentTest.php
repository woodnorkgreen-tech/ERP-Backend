<?php

namespace Tests\Feature\CostCollector;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A fund requisition is a claim about the future — cash will be needed — and is
 * therefore the petty-cash equivalent of an approved purchase order. These
 * tests pin the two halves of that: approval commits the project's money, and
 * the payment that follows releases the commitment rather than adding to it.
 */
class PettyCashCommitmentTest extends TestCase
{
    use RefreshDatabase;

    private PettyCashCostProducer $producer;
    private User $user;
    private int $departmentId;
    private int $topUpId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);

        $this->producer = app(PettyCashCostProducer::class);
        $this->user = User::factory()->create();

        $this->departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->user->id,
        ])->id;
    }

    private function expenseCode(string $jobRule = ExpenseCode::JOB_OPTIONAL): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'TST-' . strtoupper(substr(md5($jobRule), 0, 6)),
            'accounting_class' => 'Direct project cost',
            'expense_family' => 'Direct expenses',
            'expense_type' => 'Site fuel',
            'job_id_rule' => $jobRule,
            'cash_flow_class' => 'operating',
            'default_debit_account_id' => ChartOfAccount::where('code', '1211')->value('id'),
            'is_active' => true,
        ]);
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

    private function requisition(array $overrides = [], bool $coded = true, ?ExpenseCode $code = null): PettyCashRequisition
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'FUEL-' . uniqid(),
            'name' => 'Site fuel',
            'default_expense_code_id' => $coded ? ($code ?? $this->expenseCode())->id : null,
            'is_active' => true,
        ]);

        return PettyCashRequisition::create(array_merge([
            'requisition_number' => 'REQ-' . uniqid(),
            'user_id' => $this->user->id,
            'department_id' => $this->departmentId,
            'category' => 'operations',
            'requisition_type_id' => $type->id,
            'purpose' => 'Fuel for the site generator',
            'total_amount' => 12000.00,
            'status' => 'approved',
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'payee_name' => 'Total Kenya',
            'is_public' => false,
        ], $overrides));
    }

    public function test_an_approved_requisition_commits_the_projects_money(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-010');

        $this->assertSame('committed', $this->producer->commitFor(
            $this->requisition(['enquiry_id' => $enquiryId]),
        ));

        $line = CostLine::firstOrFail();
        $this->assertSame(CostLine::NATURE_COMMITTED, $line->nature);
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame($enquiryId, $line->project_enquiry_id);
        $this->assertSame('12000.00', $line->net_amount);
        $this->assertSame('Total Kenya', $line->payee_name);
        // The type's expense code, so the promise and the payment that settles
        // it are classified by the same catalogue entry.
        $this->assertSame(ExpenseCode::JOB_OPTIONAL, $line->expenseCode->job_id_rule);
    }

    public function test_re_approving_cannot_commit_the_same_money_twice(): void
    {
        $requisition = $this->requisition(['enquiry_id' => $this->enquiry('WNG-01-2026-011')]);

        $this->producer->commitFor($requisition);
        $this->producer->commitFor($requisition);

        $this->assertSame(1, CostLine::where('nature', CostLine::NATURE_COMMITTED)->count());
    }

    public function test_a_requisition_awaiting_approval_promises_nothing(): void
    {
        // Nobody has agreed to it yet, so the project's money is not spoken for.
        $this->assertSame('skipped_not_approved', $this->producer->commitFor(
            $this->requisition(['status' => 'pending', 'enquiry_id' => $this->enquiry('WNG-01-2026-012')]),
        ));

        $this->assertSame(0, CostLine::count());
    }

    public function test_office_spend_with_no_project_is_not_forced_onto_one(): void
    {
        $this->assertSame('skipped_no_project', $this->producer->commitFor($this->requisition()));
        $this->assertSame(0, CostLine::count());
    }

    public function test_paying_a_requisition_releases_its_commitment(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-013');
        $requisition = $this->requisition(['enquiry_id' => $enquiryId]);
        $this->producer->commitFor($requisition);

        $commitment = CostLine::where('nature', CostLine::NATURE_COMMITTED)->firstOrFail();
        $this->assertSame(CostLine::STATUS_VERIFIED, $commitment->status);

        $disbursement = PettyCashDisbursement::create([
            'top_up_id' => $this->topUpId,
            'requisition_id' => $requisition->id,
            'expense_code_id' => ExpenseCode::query()->value('id'),
            'amount' => 12000.00,
            'receiver' => 'Total Kenya',
            'account' => 'Cost of Sales:Site Running Costs',
            'classification' => 'operations',
            'description' => 'Fuel for the site generator',
            'job_number' => 'WNG-01-2026-013',
            'payment_method' => 'cash',
            'status' => 'active',
            'date_disbursed' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->assertSame('posted', $this->producer->postFor($disbursement));

        // The promise is discharged, not left standing beside the payment —
        // otherwise the project would carry the same money twice.
        $this->assertSame(CostLine::STATUS_REVERSED, $commitment->fresh()->status);
        $this->assertSame(1, CostLine::where('nature', CostLine::NATURE_ACTUAL)
            ->where('status', CostLine::STATUS_VERIFIED)->count());
    }

    public function test_a_voided_payment_leaves_the_promise_standing(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-014');
        $requisition = $this->requisition(['enquiry_id' => $enquiryId]);
        $this->producer->commitFor($requisition);
        $commitment = CostLine::where('nature', CostLine::NATURE_COMMITTED)->firstOrFail();

        $disbursement = PettyCashDisbursement::create([
            'top_up_id' => $this->topUpId,
            'requisition_id' => $requisition->id,
            'amount' => 12000.00,
            'receiver' => 'Total Kenya',
            'account' => 'Cost of Sales:Site Running Costs',
            'classification' => 'operations',
            'description' => 'Fuel',
            'job_number' => 'WNG-01-2026-014',
            'payment_method' => 'cash',
            'status' => 'voided',
            'date_disbursed' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->assertSame('skipped_inactive', $this->producer->postFor($disbursement));

        // The cash never left, so the money is still promised.
        $this->assertSame(CostLine::STATUS_VERIFIED, $commitment->fresh()->status);
    }

    /**
     * Every requisition type in the live system has `default_expense_code_id`
     * unset, so this is the case that actually runs today. The commitment must
     * still reach the project — an unclassified promise is worth more to a
     * budget than a missing one — and it must land where unbudgeted spend is
     * counted rather than quietly joining a category it was never assigned.
     */
    public function test_a_type_with_no_accounting_code_still_commits_the_money(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-015');

        $this->assertSame('committed', $this->producer->commitFor(
            $this->requisition(['enquiry_id' => $enquiryId], coded: false),
        ));

        $line = CostLine::firstOrFail();
        $this->assertSame(CostLine::NATURE_COMMITTED, $line->nature);
        $this->assertSame($enquiryId, $line->project_enquiry_id);
        $this->assertSame('12000.00', $line->net_amount);
        // No catalogue entry to classify it, and no budget line to consume:
        // it is real money promised against a project, and unbudgeted.
        $this->assertNull($line->expense_code_id);
        $this->assertNull($line->consumes_line_id);
    }

    public function test_office_overhead_is_never_committed_to_a_project(): void
    {
        // Airtime and staff welfare are overhead. The catalogue forbids them a
        // job number, but nothing enforces that when a cost is written and the
        // request form offers a project panel whatever the category — so a
        // requester can attach one. Committing it would charge a project for
        // office spend.
        $office = $this->expenseCode(ExpenseCode::JOB_NOT_ALLOWED);

        $this->assertSame('skipped_office_code', $this->producer->commitFor(
            $this->requisition(['enquiry_id' => $this->enquiry('WNG-01-2026-016')], code: $office),
        ));

        $this->assertSame(0, CostLine::count());
    }

    /**
     * Editing an approved requisition withdraws the approval, and the promise
     * dies with it. Until this was wired, a disbursement was the only thing
     * that released a commitment.
     */
    public function test_withdrawing_an_approval_releases_the_commitment(): void
    {
        $requisition = $this->requisition(['enquiry_id' => $this->enquiry('WNG-01-2026-017')]);
        $this->producer->commitFor($requisition);

        $this->assertSame('released', $this->producer->releaseFor($requisition, 'Edited.'));

        $line = CostLine::where('nature', CostLine::NATURE_COMMITTED)->firstOrFail();
        $this->assertSame(CostLine::STATUS_REVERSED, $line->status);
        $this->assertSame('Edited.', $line->query_note);
    }

    public function test_releasing_a_requisition_that_promised_nothing_is_harmless(): void
    {
        $this->assertSame('nothing_open', $this->producer->releaseFor($this->requisition(), 'Edited.'));
        $this->assertSame(0, CostLine::count());
    }

    /**
     * The stale-amount gap.
     *
     * postFromSource() is idempotent on the source document and returns the
     * existing line untouched, so before the source_ref carried the approval
     * instant, re-approving at a new figure left the project showing the old
     * one. Only the amount actually approved should stand.
     */
    public function test_re_approving_at_a_new_amount_commits_the_new_amount(): void
    {
        $requisition = $this->requisition(['enquiry_id' => $this->enquiry('WNG-01-2026-018')]);
        $this->producer->commitFor($requisition);

        // What editing does: the approval is withdrawn, the figure changes, and
        // somebody approves the new version a moment later.
        $this->producer->releaseFor($requisition, 'Edited.');
        $requisition->update(['total_amount' => 45000.00, 'approved_at' => now()->addMinute()]);

        $this->assertSame('committed', $this->producer->commitFor($requisition->fresh()));

        $open = CostLine::where('nature', CostLine::NATURE_COMMITTED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->get();

        $this->assertCount(1, $open, 'Exactly one promise should stand at a time.');
        $this->assertSame('45000.00', $open->first()->net_amount);
    }

    /**
     * The phantom-commitment gap.
     *
     * Rejection is only reachable from pending, so this is the one path by
     * which an approved requisition can be rejected — and with no payment
     * coming, nothing else would ever have released what it committed.
     */
    public function test_a_requisition_approved_then_edited_then_rejected_leaves_nothing_committed(): void
    {
        $requisition = $this->requisition(['enquiry_id' => $this->enquiry('WNG-01-2026-019')]);
        $this->producer->commitFor($requisition);

        $this->producer->releaseFor($requisition, 'Approval withdrawn when the requisition was edited.');
        $requisition->update(['status' => 'rejected']);

        $this->assertSame(0, CostLine::where('nature', CostLine::NATURE_COMMITTED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->count());
    }

    /** A second code the payer could pick instead — active and equally payable. */
    private function otherExpenseCode(): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'TST-OTHER',
            'accounting_class' => 'Direct project cost',
            'expense_family' => 'Direct expenses',
            'expense_type' => 'Something else entirely',
            'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
            'cash_flow_class' => 'operating',
            'default_debit_account_id' => ChartOfAccount::where('code', '1211')->value('id'),
            'is_active' => true,
        ]);
    }

    /**
     * Created here rather than by seeding FinanceReferenceSeeder: that seeder
     * also fills the expense catalogue, and these tests assert against cost
     * lines they raised themselves under their own TST- codes.
     */
    private function pettyCashSourceId(): int
    {
        return (int) (DB::table('payment_sources')->where('code', 'PC-TEST')->value('id')
            ?: DB::table('payment_sources')->insertGetId([
                'code' => 'PC-TEST', 'name' => 'Test Petty Cash Float', 'type' => 'petty_cash',
                'currency' => 'KES', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    private function payFor(PettyCashRequisition $requisition, int $expenseCodeId): array
    {
        // Somebody other than the requester, so the self-approval guard is not
        // what this test ends up measuring.
        $this->actingAs(User::factory()->create());

        // The tin's balance is a singleton row that PettyCashTopUp::create does
        // not touch, so fund it directly — this test is about how the payment is
        // classified, not about whether the float covers it.
        DB::table('petty_cash_balances')->updateOrInsert(['id' => 1], ['current_balance' => 500000.00]);

        return app(PettyCashService::class)->createDisbursement([
            'top_up_id' => $this->topUpId,
            'requisition_id' => $requisition->id,
            'expense_code_id' => $expenseCodeId,
            'payment_source_id' => $this->pettyCashSourceId(),
            'amount' => $requisition->total_amount,
            'receiver' => 'Total Kenya',
            'description' => 'Paying the request',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'date_disbursed' => now()->toDateString(),
        ]);
    }

    /**
     * The expense type is part of what was approved.
     *
     * Everything else the approval fixed is already enforced when it is paid —
     * the payee, the description, the project, the amount to the cent. The
     * classification was validated only as "some active code", so a request
     * committed under one code could be settled under any other: no double
     * count, but the promise and the money landing on different budget lines.
     */
    public function test_paying_a_requisition_classifies_it_under_the_approved_code(): void
    {
        $approved = $this->expenseCode();
        $requisition = $this->requisition(
            ['enquiry_id' => $this->enquiry('WNG-01-2026-020')],
            code: $approved,
        );
        $this->producer->commitFor($requisition);

        $result = $this->payFor($requisition, $this->otherExpenseCode()->id);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame($approved->id, (int) $result['data']->expense_code_id);
        $this->assertSame($approved->expense_type, $result['data']->account);

        // Overriding somebody's explicit choice leaves a trail.
        $this->assertDatabaseHas('petty_cash_activity_logs', [
            'action' => 'expense_code_pinned_to_requisition',
            'transaction_id' => $requisition->id,
        ]);
    }

    /**
     * The retired folk categories carry no expense code, and their approved
     * requisitions still have to be payable. With nothing approved to pin to,
     * whoever pays classifies it.
     */
    public function test_a_requisition_whose_type_has_no_code_leaves_the_payers_choice(): void
    {
        $requisition = $this->requisition(
            ['enquiry_id' => $this->enquiry('WNG-01-2026-021')],
            coded: false,
        );
        $chosen = $this->otherExpenseCode();

        $result = $this->payFor($requisition, $chosen->id);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame($chosen->id, (int) $result['data']->expense_code_id);
    }
}
