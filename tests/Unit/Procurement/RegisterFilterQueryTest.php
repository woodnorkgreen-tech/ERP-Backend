<?php

namespace Tests\Unit\Procurement;

use App\Modules\ProcurementStores\Controllers\BillController;
use App\Modules\ProcurementStores\Controllers\GoodsReceiptNoteController;
use App\Modules\ProcurementStores\Controllers\PurchaseOrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisterFilterQueryTest extends TestCase
{
    public function test_order_search_combines_supplier_search_with_status_and_date(): void
    {
        $this->assertRegisterQuery(PurchaseOrderController::class, [
            'searchTerm' => 'Acme', 'status' => 'approved', 'date_filter' => 'today',
        ], ['%Acme%', 'approved', today()->toDateString()], ['po_number', 'status', 'date']);
    }

    public function test_invoice_search_combines_supplier_search_with_payment_status_and_date(): void
    {
        $this->assertRegisterQuery(BillController::class, [
            'searchTerm' => 'Acme', 'status' => 'pending', 'date_filter' => 'today',
        ], ['%Acme%', 'pending', today()->toDateString()], ['bill_number', 'status', 'bill_date']);
    }

    public function test_receipt_search_combines_text_with_quality_store_and_date(): void
    {
        $this->assertRegisterQuery(GoodsReceiptNoteController::class, [
            'searchTerm' => 'GRN', 'quality_check' => 'pass', 'store_location' => 'Matasia Store', 'date_filter' => 'today',
        ], ['%GRN%', 'pass', 'Matasia Store', today()->toDateString()], ['grn_number', 'quality_check', 'store_location', 'date']);
    }

    private function assertRegisterQuery(string $controller, array $filters, array $bindings, array $columns): void
    {
        $queries = [];
        foreach (['index', 'search'] as $method) {
            // Compile real Eloquent queries without running SQL or migrations.
            $log = DB::connection('mysql')->pretend(fn () => app($controller)->{$method}(
                Request::create('/register', 'GET', $filters)
            ));
            $this->assertNotEmpty($log);
            $query = $log[0];
            foreach ($bindings as $binding) {
                $this->assertContains($binding, $query['bindings']);
            }
            foreach ($columns as $column) {
                $this->assertStringContainsString($column, $query['query']);
            }
            $queries[] = $query;
        }
        $this->assertSame($queries[0]['query'], $queries[1]['query']);
        $this->assertSame($queries[0]['bindings'], $queries[1]['bindings']);
    }
}
