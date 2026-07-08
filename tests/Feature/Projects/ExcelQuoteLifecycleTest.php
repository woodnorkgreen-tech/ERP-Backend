<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\Notification;
use App\Models\ProjectEnquiry;
use App\Models\TaskQuoteData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExcelQuoteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_upload_stores_file_on_private_disk_and_returns_signed_url(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $response = $this->postJson("/api/projects/tasks/{$task->id}/quote/upload-excel", [
            'file' => UploadedFile::fake()->create('quote.xlsx', 100, self::XLSX_MIME),
            'quote_amount' => 250000,
        ]);

        $response->assertOk();

        $quoteData = TaskQuoteData::where('enquiry_task_id', $task->id)->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($quoteData->excel_quote_file));
        $this->assertFalse(Storage::disk('public')->exists($quoteData->excel_quote_file));

        $fileUrl = $response->json('data.file_url');
        $this->assertStringContainsString('signature=', $fileUrl);
        $this->assertStringContainsString("projects/tasks/{$task->id}/quote/excel/download", $fileUrl);
    }

    public function test_download_requires_valid_signature(): void
    {
        $task = $this->quoteTask();
        $this->quoteData($task);

        $this->get("/api/projects/tasks/{$task->id}/quote/excel/download")
            ->assertForbidden();
    }

    public function test_signed_download_streams_the_private_file(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $this->postJson("/api/projects/tasks/{$task->id}/quote/upload-excel", [
            'file' => UploadedFile::fake()->create('quote.xlsx', 100, self::XLSX_MIME),
            'quote_amount' => 250000,
        ])->assertOk();

        $fileUrl = TaskQuoteData::where('enquiry_task_id', $task->id)->exists()
            ? $this->postJson("/api/projects/tasks/{$task->id}/quote/upload-excel", [
                'file' => UploadedFile::fake()->create('quote-v2.xlsx', 100, self::XLSX_MIME),
                'quote_amount' => 260000,
            ])->json('data.file_url')
            : null;

        $this->assertNotNull($fileUrl);
        $this->get($fileUrl)
            ->assertOk()
            ->assertDownload('quote-v2.xlsx');
    }

    public function test_approved_excel_quote_cannot_be_removed(): void
    {
        $task = $this->quoteTask();
        $quoteData = $this->quoteData($task, ['approval_status' => 'approved']);
        Storage::disk('local')->put($quoteData->excel_quote_file, 'contents');
        Sanctum::actingAs($this->user());

        $this->deleteJson("/api/projects/tasks/{$task->id}/quote/excel")
            ->assertStatus(422);

        $this->assertTrue(Storage::disk('local')->exists($quoteData->excel_quote_file));
        $this->assertSame('excel_upload', $quoteData->fresh()->quote_mode);
    }

    public function test_removal_keeps_file_referenced_by_a_version_snapshot(): void
    {
        $task = $this->quoteTask();
        $quoteData = $this->quoteData($task);
        Storage::disk('local')->put($quoteData->excel_quote_file, 'contents');

        $quoteData->versions()->create([
            'version_number' => 1,
            'label' => 'Excel Rev 1',
            'data' => ['excel_quote_file' => $quoteData->excel_quote_file, 'excel_quote_filename' => 'quote.xlsx'],
            'created_by' => $this->user()->id,
        ]);

        Sanctum::actingAs($this->user());
        $this->deleteJson("/api/projects/tasks/{$task->id}/quote/excel")->assertOk();

        $this->assertTrue(
            Storage::disk('local')->exists('quote_excel/' . $task->id . '/quote.xlsx'),
            'File referenced by a version snapshot must survive removal.'
        );
        $this->assertNull($quoteData->fresh()->excel_quote_file);
    }

    public function test_reupload_over_approved_quote_reopens_approval_and_notifies_finance(): void
    {
        $task = $this->quoteTask();
        $enquiry = $task->enquiry;
        $enquiry->update(['client_approved_quote' => 250000]);

        $this->quoteData($task, ['approval_status' => 'approved', 'excel_quote_amount' => 250000]);

        $approvalTask = EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Quote Approval',
            'type' => 'quote_approval',
            'status' => 'completed',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Approval task',
            'task_order' => 2,
            'created_by' => $enquiry->created_by,
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => $approvalTask->id,
            'enquiry_id' => $enquiry->id,
            'approval_status' => 'approved',
            'approved_by' => 'Finance Tester',
            'approval_date' => now(),
            'quote_amount' => 250000,
            'quote_data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $financeUser = $this->user();
        Permission::findOrCreate(Permissions::FINANCE_QUOTE_APPROVE, 'web');
        $financeUser->givePermissionTo(Permissions::FINANCE_QUOTE_APPROVE);

        Sanctum::actingAs($this->user());
        $this->postJson("/api/projects/tasks/{$task->id}/quote/upload-excel", [
            'file' => UploadedFile::fake()->create('quote-rev2.xlsx', 100, self::XLSX_MIME),
            'quote_amount' => 300000,
            'revision_notes' => 'Client requested scope change',
        ])->assertOk();

        $this->assertSame('in_progress', $approvalTask->fresh()->status);
        $this->assertNull($enquiry->fresh()->client_approved_quote);
        $this->assertSame(
            'pending',
            DB::table('quote_approvals')->where('enquiry_id', $enquiry->id)->value('approval_status')
        );
        $this->assertTrue(
            GovernanceAuditLog::where('project_enquiry_id', $enquiry->id)
                ->where('action_status', 'invalidated')
                ->exists()
        );
        $this->assertTrue(
            Notification::where('user_id', $financeUser->id)
                ->where('type', 'quote_approval_invalidated')
                ->exists()
        );
    }

    public function test_quote_preparation_cannot_complete_until_the_quote_is_approved(): void
    {
        $task = $this->quoteTask();
        $this->quoteData($task); // complete Excel quote, but NOT approved
        Sanctum::actingAs($this->user());

        $service = app(\App\Modules\Projects\Services\EnquiryWorkflowService::class);

        try {
            $service->updateTaskStatus($task->id, 'completed');
            $this->fail('Quote Preparation completed without an approved quote.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('must be approved', $e->getMessage());
        }
        $this->assertNotSame('completed', $task->fresh()->status);

        // Once Finance approves, completion goes through.
        TaskQuoteData::where('enquiry_task_id', $task->id)->update(['approval_status' => 'approved']);
        $service->updateTaskStatus($task->id, 'completed');
        $this->assertSame('completed', $task->fresh()->status);
    }

    private function quoteTask(): EnquiryTask
    {
        $creator = $this->user();

        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Excel Quote Test Project',
            'description' => 'Excel quote lifecycle test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator->id,
            'selected_workflow_tasks' => ['quote', 'quote_approval'],
            'workflow_preset_type' => 'external_project',
        ]);

        return EnquiryTask::create([
            'project_enquiry_id' => $enquiry->id,
            'title' => 'Quote',
            'type' => 'quote',
            'status' => 'in_progress',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'task_description' => 'Quote task',
            'task_order' => 1,
            'created_by' => $creator->id,
        ]);
    }

    private function quoteData(EnquiryTask $task, array $overrides = []): TaskQuoteData
    {
        return TaskQuoteData::create(array_merge([
            'enquiry_task_id' => $task->id,
            'quote_mode' => 'excel_upload',
            'excel_quote_file' => "quote_excel/{$task->id}/quote.xlsx",
            'excel_quote_filename' => 'quote.xlsx',
            'excel_quote_amount' => 250000,
            'quote_amount' => 250000,
        ], $overrides));
    }

    private function user(): User
    {
        return User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ])->fresh();
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
