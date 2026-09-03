<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\ProjectEnquiry;
use App\Models\TaskQuoteData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class QuoteApprovalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_save_quote_data_rejects_client_supplied_approved_status(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $this->postJson("/api/projects/tasks/{$task->id}/quote", array_merge(
            $this->quotePayload(),
            ['status' => 'approved']
        ))->assertStatus(422)
          ->assertJsonValidationErrors(['status']);
    }

    public function test_approved_quote_cannot_be_edited(): void
    {
        $task = $this->quoteTask();
        TaskQuoteData::create([
            'enquiry_task_id' => $task->id,
            'quote_mode' => 'built_in',
            'approval_status' => 'approved',
            'quote_amount' => 100000,
        ]);
        Sanctum::actingAs($this->user());

        $response = $this->postJson("/api/projects/tasks/{$task->id}/quote", $this->quotePayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('locked', $response->json('message'));
    }

    public function test_quote_save_recalculates_lines_and_totals_from_atomic_inputs(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $payload = $this->quotePayload();
        $payload['materials'] = [[
            'id' => 'element-1',
            'name' => 'Counter',
            'quantity' => 2,
            'baseTotal' => 999999,
            'marginAmount' => 999999,
            'finalTotal' => 999999,
            'materials' => [[
                'id' => 'line-1',
                'description' => 'MDF',
                'quantity' => 3,
                'days' => 1,
                'unitPrice' => 100,
                'marginPercentage' => 20,
                'totalPrice' => 999999,
                'marginAmount' => 999999,
                'finalPrice' => 999999,
                'isVisible' => true,
            ]],
        ]];
        $payload['discountAmount'] = 100;
        $payload['vatPercentage'] = 16;
        $payload['vatEnabled'] = true;
        $payload['totals'] = ['grandTotal' => 999999999];

        $this->postJson("/api/projects/tasks/{$task->id}/quote", $payload)
            ->assertOk()
            ->assertJsonPath('data.materials.0.materials.0.totalPrice', 300)
            ->assertJsonPath('data.materials.0.baseTotal', 600)
            ->assertJsonPath('data.materials.0.marginAmount', 120)
            ->assertJsonPath('data.totals.subtotal', 720)
            ->assertJsonPath('data.totals.discountAmount', 100)
            ->assertJsonPath('data.totals.grandTotal', 719.2);

        $stored = TaskQuoteData::where('enquiry_task_id', $task->id)->firstOrFail();
        $this->assertSame(719.2, (float) $stored->totals['grandTotal']);
    }

    public function test_approval_decision_requires_finance_permission(): void
    {
        [$quoteTask, $approvalTask] = $this->quoteAndApprovalTasks();
        Sanctum::actingAs($this->user());

        $this->postJson("/api/projects/tasks/{$approvalTask->id}/approval", [
            'approval_status' => 'approved',
            'quote_amount' => 500000,
        ])->assertForbidden();
    }

    public function test_approver_identity_is_taken_from_the_session_not_the_request(): void
    {
        [$quoteTask, $approvalTask] = $this->quoteAndApprovalTasks();
        $quoteTask->enquiry->update(['job_number' => 'WNG-TEST-001']);

        TaskQuoteData::create([
            'enquiry_task_id' => $quoteTask->id,
            'quote_mode' => 'built_in',
            'quote_amount' => 500000,
        ]);

        $financeUser = $this->user();
        Permission::findOrCreate(Permissions::FINANCE_QUOTE_APPROVE, 'web');
        $financeUser->givePermissionTo(Permissions::FINANCE_QUOTE_APPROVE);
        Sanctum::actingAs($financeUser);

        $this->postJson("/api/projects/tasks/{$approvalTask->id}/approval", [
            'approval_status' => 'approved',
            'approved_by' => 'Forged Name',
            'quote_amount' => 500000,
        ])->assertOk();

        $quoteData = TaskQuoteData::where('enquiry_task_id', $quoteTask->id)->firstOrFail();
        $this->assertSame($financeUser->name, $quoteData->approved_by);
        $this->assertNotSame('Forged Name', $quoteData->approved_by);
        $this->assertSame(
            $financeUser->name,
            \DB::table('quote_approvals')->where('task_id', $approvalTask->id)->value('approved_by')
        );
    }

    public function test_rejected_quote_returns_both_quote_tasks_to_work_in_progress(): void
    {
        [$quoteTask, $approvalTask] = $this->quoteAndApprovalTasks();
        $quoteTask->update(['status' => 'completed', 'completed_at' => now()]);
        $approvalTask->update(['status' => 'completed', 'completed_at' => now()]);

        TaskQuoteData::create([
            'enquiry_task_id' => $quoteTask->id,
            'quote_mode' => 'built_in',
            'quote_amount' => 500000,
            'approval_status' => 'approved',
            'status' => 'approved',
        ]);

        $financeUser = $this->user();
        Permission::findOrCreate(Permissions::FINANCE_QUOTE_APPROVE, 'web');
        $financeUser->givePermissionTo(Permissions::FINANCE_QUOTE_APPROVE);
        Sanctum::actingAs($financeUser);

        $this->postJson("/api/projects/tasks/{$approvalTask->id}/approval", [
            'approval_status' => 'rejected',
            'rejection_reason' => 'Margin requires revision',
            'quote_amount' => 500000,
        ])->assertOk();

        $this->assertSame('in_progress', $quoteTask->fresh()->status);
        $this->assertNull($quoteTask->fresh()->completed_at);
        $this->assertSame('in_progress', $approvalTask->fresh()->status);
        $this->assertNull($approvalTask->fresh()->completed_at);
        $this->assertSame(
            'rejected',
            \DB::table('quote_approvals')->where('task_id', $approvalTask->id)->value('approval_status')
        );
    }

    public function test_payment_update_is_scoped_to_the_route_enquiry(): void
    {
        $enquiryA = $this->enquiry();
        $enquiryB = $this->enquiry();

        $paymentOnB = EnquiryPayment::create([
            'project_enquiry_id' => $enquiryB->id,
            'amount' => 50000,
            'payment_date' => now(),
            'recorded_by' => $this->user()->id,
        ]);

        // The route is gated on `finance.receivables.correct`. Without it the
        // request stops at 403 and never reaches the scoping check this test
        // exists to prove — so the guard would look effective while being
        // untested. Grant the permission, then assert the scoping.
        $actor = $this->user();
        $actor->givePermissionTo(
            \Spatie\Permission\Models\Permission::findOrCreate(
                \App\Constants\Permissions::FINANCE_RECEIVABLES_CORRECT,
                'web',
            ),
        );
        Sanctum::actingAs($actor);

        // Attempt to edit enquiry B's payment through enquiry A's URL
        $this->putJson("/api/projects/enquiries/{$enquiryA->id}/payments/{$paymentOnB->id}", [
            'amount' => 1,
            'reason' => 'cross-enquiry tampering attempt',
        ])->assertNotFound();

        $this->assertSame(50000.0, (float) $paymentOnB->fresh()->amount);
    }

    private function quoteTask(): EnquiryTask
    {
        return $this->task($this->enquiry(), 'quote');
    }

    private function quoteAndApprovalTasks(): array
    {
        $enquiry = $this->enquiry();

        return [
            $this->task($enquiry, 'quote'),
            $this->task($enquiry, 'quote_approval', ['task_order' => 2]),
        ];
    }

    private function quotePayload(): array
    {
        return [
            'materials' => [],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'margins' => ['materials' => 60, 'labour' => 0, 'expenses' => 0, 'logistics' => 0],
            'totals' => ['grandTotal' => 0],
        ];
    }

    private function enquiry(): ProjectEnquiry
    {
        return ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Integrity Test Project',
            'description' => 'Quote approval integrity test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $this->user()->id,
            'selected_workflow_tasks' => ['quote', 'quote_approval'],
            'workflow_preset_type' => 'external_project',
        ]);
    }

    private function task(ProjectEnquiry $enquiry, string $type, array $overrides = []): EnquiryTask
    {
        return EnquiryTask::create(array_merge([
            'project_enquiry_id' => $enquiry->id,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'type' => $type,
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Integrity test task',
            'task_order' => 1,
            'created_by' => $enquiry->created_by,
        ], $overrides));
    }

    private function user(): User
    {
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ])->fresh();

        // `quote.access` requires an EnquiryConstants::FINANCIAL_QUOTE_ROLES
        // role; that middleware post-dates this suite, so every request here
        // answered 403. Costing satisfies it without being in ROLES_ADMIN,
        // which would bypass the very guards these tests assert.
        $user->assignRole(Role::findOrCreate('Costing', 'web'));

        return $user->fresh();
    }

    private function client(): Client
    {
        return Client::create([
            'full_name' => 'Acme Test Client',
            'contact_person' => 'Jane Test',
            'email' => uniqid('client_') . '@test.local',
            'phone' => '0700000000',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'test',
            'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);
    }
}
