<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Models\TaskQuoteData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExcelQuoteVersionCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_inspect_endpoint_detects_workbook_total_without_storing(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Line item');
        $sheet->setCellValue('B1', 120000);
        $sheet->setCellValue('A2', 'Grand Total');
        $sheet->setCellValue('B2', 174000);

        $response = $this->postJson("/api/projects/tasks/{$task->id}/quote/inspect-excel", [
            'file' => $this->asUploadedFile($spreadsheet, 'client-quote.xlsx'),
        ]);

        $response->assertOk();
        $this->assertEqualsWithDelta(174000.0, $response->json('data.detected_total'), 0.01);

        // Nothing stored: no quote record created, no files kept
        $this->assertNull(TaskQuoteData::where('enquiry_task_id', $task->id)->first());
        $this->assertSame([], Storage::disk('local')->allFiles("quote_excel/{$task->id}"));
    }

    public function test_a_single_version_can_be_deleted_with_its_orphaned_file(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $quoteData = TaskQuoteData::create([
            'enquiry_task_id' => $task->id,
            'quote_mode' => 'excel_upload',
            'excel_quote_file' => "quote_excel/{$task->id}/current.xlsx",
            'quote_amount' => 100000,
        ]);
        Storage::disk('local')->put("quote_excel/{$task->id}/current.xlsx", 'current');
        Storage::disk('local')->put("quote_excel/{$task->id}/old.xlsx", 'old');

        $version = $quoteData->versions()->create([
            'version_number' => 1,
            'label' => 'Excel Rev 1 — old.xlsx',
            'data' => ['excel_quote_file' => "quote_excel/{$task->id}/old.xlsx", 'excel_quote_filename' => 'old.xlsx'],
            'created_by' => $this->user()->id,
        ]);

        $this->deleteJson("/api/projects/tasks/{$task->id}/quote/versions/{$version->id}")
            ->assertOk();

        $this->assertDatabaseMissing('quote_versions', ['id' => $version->id]);
        $this->assertFalse(Storage::disk('local')->exists("quote_excel/{$task->id}/old.xlsx"));
        // Current file untouched
        $this->assertTrue(Storage::disk('local')->exists("quote_excel/{$task->id}/current.xlsx"));
    }

    public function test_clear_versions_removes_all_history_but_is_blocked_when_approved(): void
    {
        $task = $this->quoteTask();
        Sanctum::actingAs($this->user());

        $quoteData = TaskQuoteData::create([
            'enquiry_task_id' => $task->id,
            'quote_mode' => 'excel_upload',
            'excel_quote_file' => "quote_excel/{$task->id}/current.xlsx",
            'quote_amount' => 100000,
        ]);

        foreach ([1, 2] as $n) {
            $quoteData->versions()->create([
                'version_number' => $n,
                'label' => "Rev {$n}",
                'data' => ['excel_quote_file' => "quote_excel/{$task->id}/v{$n}.xlsx"],
                'created_by' => $this->user()->id,
            ]);
        }

        // Approved quote → history is locked
        $quoteData->update(['approval_status' => 'approved']);
        $this->deleteJson("/api/projects/tasks/{$task->id}/quote/versions")->assertStatus(422);
        $this->assertSame(2, $quoteData->versions()->count());

        // Unapproved → clearing works
        $quoteData->update(['approval_status' => 'pending']);
        $this->deleteJson("/api/projects/tasks/{$task->id}/quote/versions")->assertOk();
        $this->assertSame(0, $quoteData->versions()->count());
    }

    private function asUploadedFile(Spreadsheet $spreadsheet, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'quote_v_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, self::XLSX_MIME, null, true);
    }

    private function quoteTask(): EnquiryTask
    {
        $creator = $this->user();

        $enquiry = ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Version Cleanup Test Project',
            'description' => 'Excel version cleanup test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $creator->id,
            'selected_workflow_tasks' => ['quote'],
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
