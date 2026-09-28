<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use Illuminate\Support\Facades\DB;
use App\Modules\Finance\PettyCash\Models\DirectDisbursementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinanceWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_only_returns_work_the_user_may_action(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-test-1', 'status' => 'pending_approval',
            'payload' => ['payee_name' => 'Test Supplier', 'amount' => 1250], 'requested_by' => $this->requester()->id,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue')
            ->assertOk()->assertJsonPath('data.summary.total', 0);

        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.work_type', 'direct_disbursement');
    }

    public function test_completed_work_disappears_from_queue_count(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $request = DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-test-2', 'status' => 'pending_approval',
            'payload' => ['amount' => 500], 'requested_by' => $this->requester()->id,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue/count')
            ->assertOk()->assertJsonPath('data.total', 1);

        $request->update(['status' => 'approved']);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue/count')
            ->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_queue_supports_server_side_search_and_pagination(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        foreach (range(1, 12) as $number) {
            DirectDisbursementRequest::create([
                'idempotency_key' => "queue-page-{$number}", 'status' => 'pending_approval',
                'payload' => ['payee_name' => $number === 12 ? 'Unique Vendor' : "Vendor {$number}", 'amount' => $number],
                'requested_by' => $this->requester()->id,
            ]);
        }

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue?per_page=10&page=2')
            ->assertOk()->assertJsonPath('data.meta.total', 12)->assertJsonCount(2, 'data.items');

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue?search=Unique%20Vendor')
            ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.counterparty', 'Unique Vendor');
    }

    public function test_user_can_claim_and_release_an_eligible_item(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $request = DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-claim', 'status' => 'pending_approval',
            'payload' => ['amount' => 500], 'requested_by' => $this->requester()->id,
        ]);

        $url = "/api/finance/work-queue/direct_disbursement/{$request->id}/claim";
        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk()
            ->assertJsonPath('data.assigned_to', $user->id);
        $this->assertDatabaseHas('finance_work_assignments', ['source_id' => $request->id, 'assigned_to' => $user->id]);
        $this->assertDatabaseHas('finance_work_assignment_events', ['source_id' => $request->id, 'event' => 'claimed']);

        $this->actingAs($user, 'sanctum')->deleteJson($url)->assertOk();
        $this->assertDatabaseMissing('finance_work_assignments', ['source_id' => $request->id]);
        $this->assertDatabaseHas('finance_work_assignment_events', ['source_id' => $request->id, 'event' => 'released']);
    }

    public function test_a_second_officer_cannot_take_an_existing_claim(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);
        $first->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $second->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $request = DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-conflict', 'status' => 'pending_approval',
            'payload' => ['amount' => 500], 'requested_by' => $this->requester()->id,
        ]);
        $url = "/api/finance/work-queue/direct_disbursement/{$request->id}/claim";

        $this->actingAs($first, 'sanctum')->postJson($url)->assertOk();
        $this->actingAs($second, 'sanctum')->postJson($url)->assertStatus(409);
        $this->assertDatabaseHas('finance_work_assignments', ['source_id' => $request->id, 'assigned_to' => $first->id]);
    }

    // ── Report 56: complete, maker/checker-aware work types ───────────────

    private ?User $requesterUser = null;

    private function requester(): User
    {
        return $this->requesterUser ??= User::factory()->create(['is_active' => true]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function types(User $user): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue/count')
            ->assertOk()->json('data.by_type');
    }

    private function enquiry(?int $officer = null): ProjectEnquiry
    {
        return ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => Client::factory()->create()->id, 'title' => 'Queue test stand',
            'description' => 'Work queue test', 'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED, 'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-WQ-'.uniqid(), 'created_by' => $this->requester()->id,
            'selected_workflow_tasks' => ['design'], 'workflow_preset_type' => 'external_project',
            'project_officer_id' => $officer, 'job_number' => 'JOB-WQ-'.uniqid(),
        ]);
    }

    private function invoice(ProjectEnquiry $enquiry, int $preparer, array $state = []): int
    {
        return DB::table('project_invoices')->insertGetId(array_merge([
            'invoice_number' => 'INV-WQ-'.uniqid(), 'project_enquiry_id' => $enquiry->id,
            'invoice_date' => '2026-09-10', 'due_date' => '2026-10-10', 'subtotal' => 1000,
            'total_amount' => 1160, 'status' => 'draft', 'created_by' => $preparer,
            'created_at' => now(), 'updated_at' => now(),
        ], $state));
    }

    public function test_the_maker_is_never_offered_their_own_work(): void
    {
        $approver = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-own', 'status' => 'pending_approval',
            'payload' => ['amount' => 900], 'requested_by' => $approver->id,
        ]);

        $this->assertArrayNotHasKey('direct_disbursement', $this->types($approver));
        $other = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $this->assertSame(1, $this->types($other)['direct_disbursement']);

        // The documented exception: a holder of self-approval may act on their own.
        $approver->givePermissionTo(Permission::findOrCreate(Permissions::APPROVALS_SELF_APPROVE, 'web'));
        $this->assertSame(1, $this->types($approver->fresh())['direct_disbursement']);
    }

    public function test_invoices_flow_from_check_to_issue_and_back_to_their_preparer(): void
    {
        $enquiry = $this->enquiry();
        $preparer = $this->userWith(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS);
        $checker = $this->userWith(Permissions::FINANCE_RECEIVABLES_INVOICE_CHECK);
        $issuer = $this->userWith(Permissions::FINANCE_RECEIVABLES_BILLING_BASIS);
        $id = $this->invoice($enquiry, $preparer->id);

        // Only someone other than the preparer is asked to check it (no self-approval exception here).
        $this->assertArrayNotHasKey('invoice_check', $this->types($preparer));
        $this->assertSame(1, $this->types($checker)['invoice_check']);
        $this->assertArrayNotHasKey('invoice_issue', $this->types($issuer));

        DB::table('project_invoices')->where('id', $id)->update(['checked_by' => $checker->id, 'checked_at' => now()]);
        $this->assertArrayNotHasKey('invoice_check', $this->types($checker));
        $this->assertSame(1, $this->types($issuer)['invoice_issue']);

        // Returned: the work is with the preparer, not with a checker.
        DB::table('project_invoices')->where('id', $id)->update([
            'checked_by' => null, 'checked_at' => null, 'returned_by' => $checker->id, 'returned_at' => now(), 'return_reason' => 'Wrong client',
        ]);
        $this->assertArrayNotHasKey('invoice_check', $this->types($checker));
        $this->assertSame(1, $this->types($preparer)['invoice_correction']);

        // Resubmitted: back to checking.
        DB::table('project_invoices')->where('id', $id)->update(['resubmitted_at' => now()->addMinute()]);
        $this->assertSame(1, $this->types($checker)['invoice_check']);
        $this->assertArrayNotHasKey('invoice_correction', $this->types($preparer));
    }

    public function test_a_receipt_is_not_offered_to_the_person_who_recorded_it(): void
    {
        $enquiry = $this->enquiry();
        $recorder = $this->userWith(Permissions::FINANCE_RECEIVABLES_VERIFY);
        $verifier = $this->userWith(Permissions::FINANCE_RECEIVABLES_VERIFY);
        DB::table('enquiry_payments')->insert([
            'project_enquiry_id' => $enquiry->id, 'amount' => 5000, 'payment_date' => '2026-09-10',
            'recorded_by' => $recorder->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertArrayNotHasKey('client_receipt', $this->types($recorder));
        $this->assertSame(1, $this->types($verifier)['client_receipt']);
    }

    public function test_a_voucher_is_posted_by_a_third_person_after_any_senior_approval(): void
    {
        $requester = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_CREATE, Permissions::FINANCE_SPEND_VOUCHERS_POST);
        $approver = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_SPEND_VOUCHERS_POST);
        $poster = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_POST);
        $senior = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE_SENIOR);
        $id = DB::table('spend_vouchers')->insertGetId([
            'voucher_no' => 'PV-WQ-1', 'type' => 'payment', 'transacted_at' => now(), 'posting_date' => '2026-09-10',
            'status' => 'approved', 'review_state' => 'awaiting_senior_approval', 'requester_user_id' => $requester->id,
            'approved_by' => $approver->id, 'approved_at' => now(), 'total_amount' => 250000, 'currency' => 'KES',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Held for senior approval: nobody may post yet.
        $this->assertArrayNotHasKey('spend_voucher_post', $this->types($poster));
        $this->assertSame(1, $this->types($senior)['spend_voucher_senior']);

        DB::table('spend_vouchers')->where('id', $id)->update(['review_state' => 'approved']);
        $this->assertSame(1, $this->types($poster)['spend_voucher_post']);
        $this->assertArrayNotHasKey('spend_voucher_post', $this->types($requester));
        $this->assertArrayNotHasKey('spend_voucher_post', $this->types($approver));

        // Returned for correction: with the requester, not the approver.
        DB::table('spend_vouchers')->where('id', $id)->update(['status' => 'pending_approval', 'review_state' => 'returned_for_correction']);
        $this->assertArrayNotHasKey('spend_voucher', $this->types($approver));
        $this->assertSame(1, $this->types($requester)['spend_voucher_correction']);
    }

    public function test_cash_requisitions_follow_approval_disbursement_and_surrender(): void
    {
        $requester = $this->requester();
        $reviewer = $this->userWith(Permissions::FINANCE_PETTY_CASH_UPDATE);
        $cashier = $this->userWith(Permissions::FINANCE_PETTY_CASH_CREATE);
        $department = DB::table('departments')->insertGetId(['name' => 'Queue Dept', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('petty_cash_requisitions')->insertGetId([
            'requisition_number' => 'REQ-WQ-1', 'user_id' => $requester->id, 'department_id' => $department,
            'category' => 'general', 'purpose' => 'Queue test', 'total_amount' => 3000, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->types($reviewer)['fund_requisition']);
        DB::table('petty_cash_requisitions')->where('id', $id)->update(['status' => 'approved', 'approved_at' => now()]);
        $this->assertSame(1, $this->types($cashier)['fund_disbursement']);
        DB::table('petty_cash_requisitions')->where('id', $id)->update(['status' => 'disbursed']);
        $this->assertSame(1, $this->types($requester)['fund_surrender']);
        $this->assertArrayNotHasKey('fund_surrender', $this->types($cashier));
        DB::table('petty_cash_requisitions')->where('id', $id)->update(['status' => 'surrender_pending', 'surrendered_at' => now()]);
        $this->assertSame(1, $this->types($cashier)['fund_surrender_review']);
        $this->assertArrayNotHasKey('fund_surrender', $this->types($requester));
    }

    public function test_labour_po_verification_is_scoped_to_the_officers_project(): void
    {
        $officer = $this->userWith(Permissions::FINANCE_LABOUR_PO_VERIFY);
        $otherOfficer = $this->userWith(Permissions::FINANCE_LABOUR_PO_VERIFY);
        $finance = $this->userWith(Permissions::FINANCE_LABOUR_FINANCE_VERIFY);
        $enquiry = $this->enquiry($officer->id);
        $id = DB::table('project_labour_actuals')->insertGetId([
            'project_enquiry_id' => $enquiry->id, 'labour_role' => 'Carpenter', 'labour_category' => 'direct',
            'unit_rate' => 1500, 'calculated_cost' => 3000, 'work_date' => '2026-09-10', 'status' => 'recorded',
            'recorded_by' => $officer->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->types($officer)['labour_po_verify']);
        $this->assertArrayNotHasKey('labour_po_verify', $this->types($otherOfficer));
        $this->assertArrayNotHasKey('labour_finance_verify', $this->types($finance));

        DB::table('project_labour_actuals')->where('id', $id)->update(['status' => 'po_verified', 'po_verified_by' => $officer->id, 'po_verified_at' => now()]);
        $this->assertSame(1, $this->types($finance)['labour_finance_verify']);
        // Two distinct responsibilities: the Project Officer is never offered Finance verification.
        $this->assertArrayNotHasKey('labour_finance_verify', $this->types($officer));
    }

    public function test_payroll_is_locked_and_paid_by_different_people(): void
    {
        $creator = $this->userWith(Permissions::HR_MANAGE_PAYROLL);
        $locker = $this->userWith(Permissions::HR_MANAGE_PAYROLL);
        $id = DB::table('payroll_runs')->insertGetId([
            'payroll_month' => '2026-09', 'status' => 'processing', 'created_by' => $creator->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertArrayNotHasKey('payroll_lock', $this->types($creator));
        $this->assertSame(1, $this->types($locker)['payroll_lock']);

        DB::table('payroll_runs')->where('id', $id)->update(['status' => 'locked', 'locked_by' => $locker->id]);
        $this->assertArrayNotHasKey('payroll_payment', $this->types($locker));
        $this->assertSame(1, $this->types($creator)['payroll_payment']);
    }

    public function test_counts_are_grouped_by_area_and_match_the_list(): void
    {
        $user = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, Permissions::FINANCE_RECEIVABLES_VERIFY);
        DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-area', 'status' => 'pending_approval',
            'payload' => ['amount' => 100], 'requested_by' => $this->requester()->id,
        ]);
        DB::table('enquiry_payments')->insert([
            'project_enquiry_id' => $this->enquiry()->id, 'amount' => 700, 'payment_date' => '2026-09-10',
            'recorded_by' => $this->requester()->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $count = $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue/count')->assertOk()->json('data');
        $this->assertSame(2, $count['total']);
        $this->assertSame(1, $count['by_area']['cash']);
        $this->assertSame(1, $count['by_area']['sales']);
        $this->assertEquals(['direct_disbursement' => 'Direct disbursement', 'client_receipt' => 'Client receipt'], $count['type_labels']);
        $this->assertEquals(['client_receipt' => 'sales', 'direct_disbursement' => 'cash'], $count['type_areas']);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue?area=sales')->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.work_type', 'client_receipt')
            ->assertJsonPath('data.items.0.required_action', 'Verify receipt')
            ->assertJsonPath('data.items.0.area', 'sales');
    }
}
