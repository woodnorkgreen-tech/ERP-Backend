<?php

namespace Tests\Feature\PettyCash;

use App\Models\User;
use App\Modules\Finance\PettyCash\Services\OfflineBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OfflineBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_stages_formula_as_an_error_without_moving_cash(): void
    {
        $user = User::factory()->create();
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Instructions')->setCellValue('B2', OfflineBatchService::VERSION);
        $sheets = [
            'TopUps' => ['offline_reference', 'date_received', 'amount', 'payment_method', 'transaction_code', 'description'],
            'Requisitions' => ['offline_reference', 'requester_email', 'department_id', 'type_code', 'purpose', 'payee_name', 'payee_phone', 'project_name', 'venue', 'custom_fields_json', 'items_json'],
            'Payouts' => ['offline_reference', 'requisition_reference', 'date_paid', 'receiver', 'amount', 'transaction_cost', 'expense_code', 'payment_source_code', 'transaction_code', 'receipt_type', 'receipt_number', 'tax_amount', 'description', 'direct_payment_reason'],
        ];
        foreach ($sheets as $name => $headers) $book->createSheet()->setTitle($name)->fromArray($headers);
        $book->getSheetByName('TopUps')->fromArray(['OFF-001', '2026-08-23', '=1000+500', 'cash', 'CASH-001', 'Field float'], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'offline-test-').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'offline.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $batch = app(OfflineBatchService::class)->stage($file, $user->id);

        $this->assertSame('invalid', $batch->status);
        $this->assertStringContainsString('Formulas are not allowed', $batch->rows->first()->errors[0]);
        $this->assertDatabaseCount('petty_cash_top_ups', 0);
        $this->assertDatabaseCount('petty_cash_disbursements', 0);
    }
}
