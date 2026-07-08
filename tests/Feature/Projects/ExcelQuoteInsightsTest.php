<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use App\Models\TaskQuoteData;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExcelQuoteInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_upload_detects_workbook_total_and_computes_budget_margin(): void
    {
        $enquiry = $this->enquiry();
        $quoteTask = $this->task($enquiry, 'quote');
        $this->budgetBaseline($enquiry, 100000);
        Sanctum::actingAs($this->user());

        // Workbook declares a grand total of 174,000; typed amount agrees.
        // Net of 16% VAT = 150,000 → implied margin vs 100,000 budget = 50%.
        $file = $this->workbook([
            ['LED Screens', 120000],
            ['Crew', 33000],
            ['Grand Total', 174000],
        ]);

        $response = $this->postJson("/api/projects/tasks/{$quoteTask->id}/quote/upload-excel", [
            'file' => $file,
            'quote_amount' => 174000,
        ]);

        $response->assertOk();
        $insights = $response->json('data.insights');
        $this->assertEqualsWithDelta(174000.0, $insights['detected_workbook_total'], 0.01);
        $this->assertSame('matched', $insights['amount_match']);
        $this->assertEqualsWithDelta(100000.0, $insights['budget_cost'], 0.01);
        $this->assertEqualsWithDelta(50.0, $insights['implied_margin_pct'], 0.01);
        $this->assertSame('healthy', $insights['margin_flag']);
        $this->assertEqualsWithDelta(121800.0, $insights['mobilization_threshold_amount'], 0.01);
        $this->assertSame([], $response->json('advisories'));

        $stored = TaskQuoteData::where('enquiry_task_id', $quoteTask->id)->firstOrFail();
        $this->assertSame('matched', $stored->excel_quote_insights['amount_match']);
    }

    public function test_amount_mismatch_and_loss_margin_produce_advisories_without_blocking(): void
    {
        $enquiry = $this->enquiry();
        $quoteTask = $this->task($enquiry, 'quote');
        $this->budgetBaseline($enquiry, 100000);
        Sanctum::actingAs($this->user());

        // Workbook says 174,000 but the user types 90,000 — which is also
        // below the 100,000 budget cost (net 77,586) → loss.
        $file = $this->workbook([['Grand Total', 174000]]);

        $response = $this->postJson("/api/projects/tasks/{$quoteTask->id}/quote/upload-excel", [
            'file' => $file,
            'quote_amount' => 90000,
        ]);

        $response->assertOk(); // advisories never block
        $insights = $response->json('data.insights');
        $this->assertSame('mismatch', $insights['amount_match']);
        $this->assertSame('loss', $insights['margin_flag']);
        $this->assertCount(2, $response->json('advisories'));
    }

    public function test_approval_screen_receives_fresh_financial_context(): void
    {
        $enquiry = $this->enquiry();
        $quoteTask = $this->task($enquiry, 'quote');
        $approvalTask = $this->task($enquiry, 'quote_approval', ['task_order' => 2]);
        $this->budgetBaseline($enquiry, 100000);

        \DB::table('quote_approvals')->insert([
            'task_id' => $approvalTask->id,
            'enquiry_id' => $enquiry->id,
            'approval_status' => 'pending',
            'approved_by' => 'Pending',
            'approval_date' => now(),
            'quote_amount' => 174000,
            'quote_data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user());

        $response = $this->getJson("/api/projects/tasks/{$approvalTask->id}/approval");

        $response->assertOk();
        $context = $response->json('data.financialContext');
        $this->assertEqualsWithDelta(100000.0, $context['budget_cost'], 0.01);
        $this->assertEqualsWithDelta(50.0, $context['implied_margin_pct'], 0.01);
        $this->assertSame('healthy', $context['margin_flag']);
    }

    public function test_payment_progress_never_measures_against_an_unapproved_quote(): void
    {
        $enquiry = $this->enquiry();
        $quoteTask = $this->task($enquiry, 'quote');

        // A draft (unapproved) quote exists with an amount, but no
        // client_approved_quote is anchored on the enquiry.
        TaskQuoteData::create([
            'enquiry_task_id' => $quoteTask->id,
            'quote_mode' => 'excel_upload',
            'quote_amount' => 500000,
            'status' => 'pending',
        ]);

        $progress = app(FinanceService::class)->getPaymentProgress($enquiry);

        $this->assertSame('none', $progress['quote_basis']);
        $this->assertSame(0.0, $progress['total_quote']);
        $this->assertFalse($progress['is_70_percent_met']);

        // Once approved, the same quote becomes a valid basis.
        TaskQuoteData::where('enquiry_task_id', $quoteTask->id)
            ->update(['approval_status' => 'approved']);

        $progress = app(FinanceService::class)->getPaymentProgress($enquiry->fresh());

        $this->assertSame('system_quote', $progress['quote_basis']);
        $this->assertSame(500000.0, $progress['total_quote']);
    }

    /** @param list<array{0: string, 1: float}> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $r = 1;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $r++;
        }

        $path = tempnam(sys_get_temp_dir(), 'quote_wb_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'client-quote.xlsx', self::XLSX_MIME, null, true);
    }

    private function budgetBaseline(ProjectEnquiry $enquiry, float $grandTotal): TaskBudgetData
    {
        $budgetTask = $this->task($enquiry, 'budget', ['task_order' => 0]);

        return TaskBudgetData::create([
            'enquiry_task_id' => $budgetTask->id,
            'project_info' => [],
            'materials_data' => [],
            'labour_data' => [],
            'expenses_data' => [],
            'logistics_data' => [],
            'budget_summary' => [
                'materialsTotal' => $grandTotal,
                'labourTotal' => 0,
                'expensesTotal' => 0,
                'logisticsTotal' => 0,
                'grandTotal' => $grandTotal,
            ],
            'status' => 'approved',
        ]);
    }

    private function enquiry(): ProjectEnquiry
    {
        return ProjectEnquiry::create([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Insights Test Project',
            'description' => 'Excel quote insights test',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'created_by' => $this->user()->id,
            'selected_workflow_tasks' => ['budget', 'quote', 'quote_approval'],
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
            'task_description' => 'Insights test task',
            'task_order' => 1,
            'created_by' => $enquiry->created_by,
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
