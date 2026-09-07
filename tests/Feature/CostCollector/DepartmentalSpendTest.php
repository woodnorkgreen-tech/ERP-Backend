<?php

namespace Tests\Feature\CostCollector;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\HR\Models\Department;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Office and overhead purchases are spend too.
 *
 * `ProcurementCostProducer::postPurchaseOrder()` used to skip any order line
 * with no job — "departmental procurement has no project cost object" — while
 * the goods-receipt path had no such guard and accrued the same purchase
 * anyway. So a departmental order committed nothing and then produced an
 * accrual from nowhere on delivery. Five of the six requisitions in the live
 * database are non-project, so that was the ordinary case.
 *
 * The premise was the error. Departmental spend does have a cost object: the
 * department that asked for it, which every requisition carries and which
 * `cost_centres.hr_department_id` maps to a finance cost centre.
 */
class DepartmentalSpendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // Departments first: the dimension seeder links cost centres to them by
        // name, and a cost centre with no department cannot own office spend.
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);

        $this->user = User::create([
            'name' => 'Procurement Officer',
            'email' => uniqid('proc_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Office Supplies Ltd',
            'contact_person' => 'Supplier Contact',
            'phone' => '0700000003',
            'email' => uniqid('supplier_').'@test.local',
            'address' => 'Industrial Area',
            'payment_terms' => '30 days',
            'status' => 'Active',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_every_department_has_exactly_one_cost_centre(): void
    {
        $departments = Department::pluck('name', 'id');

        $this->assertNotEmpty($departments, 'The department roster is the input to this mapping.');

        foreach ($departments as $id => $name) {
            $centres = DB::table('cost_centres')->where('hr_department_id', $id)->count();

            $this->assertSame(
                1, $centres,
                "Department \"{$name}\" maps to {$centres} cost centres; it must map to exactly one, "
                .'otherwise its spend either has no owner or has an ambiguous one.'
            );
        }
    }

    public function test_the_expense_catalogue_resolves_its_own_dimensions(): void
    {
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        // "Asset-owning department" names no single centre and is meant to stay
        // null; everything else in the catalogue must resolve.
        $unresolved = DB::table('expense_codes')
            ->where('is_active', true)
            ->whereNotNull('default_cost_centre')
            ->where('default_cost_centre', '!=', 'Asset-owning department')
            ->whereNull('default_cost_centre_id')
            ->pluck('code');

        $this->assertEmpty(
            $unresolved,
            'These codes name a department that resolves to no cost centre: '.$unresolved->implode(', ')
        );

        $unresolvedActivity = DB::table('expense_codes')
            ->where('is_active', true)
            ->whereNotNull('project_activity')
            ->whereNull('default_activity_id')
            ->pluck('code');

        $this->assertEmpty(
            $unresolvedActivity,
            'These codes name a stage that resolves to no activity: '.$unresolvedActivity->implode(', ')
        );
    }

    public function test_a_departmental_purchase_order_commits_against_its_department(): void
    {
        $procurement = Department::where('name', 'Procurement')->firstOrFail();
        $order = $this->approvedOrder($procurement->id);

        $posted = app(ProcurementCostProducer::class)->postPurchaseOrder($order->id);

        $this->assertSame(1, $posted, 'A departmental order must commit, not be silently skipped.');

        $line = CostLine::where('source_type', PurchaseOrderItem::class)->sole();

        $this->assertSame(CostLine::NATURE_COMMITTED, $line->nature);
        $this->assertNull($line->project_enquiry_id, 'This purchase belongs to no job.');
        $this->assertNotNull(
            $line->cost_centre_id,
            'Office spend with no job still has an owner: the department that requested it.'
        );
        $this->assertSame(
            'PROC',
            DB::table('cost_centres')->where('id', $line->cost_centre_id)->value('code'),
            'The commitment must land on the requesting department, not on the catalogue default.'
        );
    }

    public function test_the_requesting_department_outranks_the_catalogue_default(): void
    {
        // A catalogue code whose default names Finance, requisitioned by
        // Production. The producer holds the better answer and must win.
        $code = $this->expenseCode('FIN');
        $production = Department::where('name', 'Production')->firstOrFail();
        $order = $this->approvedOrder($production->id, $code);

        app(ProcurementCostProducer::class)->postPurchaseOrder($order->id);

        $line = CostLine::where('source_type', PurchaseOrderItem::class)->sole();

        $this->assertSame(
            'PROD',
            DB::table('cost_centres')->where('id', $line->cost_centre_id)->value('code'),
            'A department stated by the producer beats the catalogue guess about who usually buys this.'
        );
    }

    public function test_a_purchase_with_no_department_falls_back_to_the_catalogue(): void
    {
        $order = $this->approvedOrder(null, $this->expenseCode('FIN'));

        app(ProcurementCostProducer::class)->postPurchaseOrder($order->id);

        $line = CostLine::where('source_type', PurchaseOrderItem::class)->sole();

        $this->assertSame(
            'FIN',
            DB::table('cost_centres')->where('id', $line->cost_centre_id)->value('code'),
            'With no department stated, the catalogue default is the weakest answer but still an answer.'
        );
    }

    public function test_a_purchase_with_no_owner_at_all_is_still_recorded(): void
    {
        $order = $this->approvedOrder(null, $this->uncategorisedExpenseCode());

        $posted = app(ProcurementCostProducer::class)->postPurchaseOrder($order->id);

        $this->assertSame(1, $posted, 'An unattributed purchase is still real spend and must not be dropped.');
        $this->assertNull(
            CostLine::where('source_type', PurchaseOrderItem::class)->sole()->cost_centre_id,
            'Refusing the cost over a missing reporting attribute would lose the spend entirely.'
        );
    }

    /** A catalogue code that names no department, like "Asset-owning department". */
    private function uncategorisedExpenseCode(): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'TST-UNC-'.uniqid(),
            'accounting_class' => 'Operating expense',
            'expense_family' => 'Office costs',
            'expense_type' => 'Uncategorised',
            'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED,
            'cash_flow_class' => 'operating',
            'default_debit_account_id' => ChartOfAccount::where('code', '1211')->value('id'),
            'default_cost_centre_id' => null,
            'is_active' => true,
        ]);
    }

    private function expenseCode(string $centreCode = 'PROC'): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => 'TST-OFF-'.uniqid(),
            'accounting_class' => 'Operating expense',
            'expense_family' => 'Office costs',
            'expense_type' => 'Stationery',
            'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED,
            'cash_flow_class' => 'operating',
            'default_debit_account_id' => ChartOfAccount::where('code', '1211')->value('id'),
            'default_cost_centre_id' => DB::table('cost_centres')->where('code', $centreCode)->value('id'),
            'is_active' => true,
        ]);
    }

    private function approvedOrder(?int $departmentId, ?ExpenseCode $code = null): PurchaseOrder
    {
        $requisition = Requisition::create([
            'requisition_number' => 'PR-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'department_id' => $departmentId,
            'urgency' => 'normal',
            'total_amount' => 12000,
            'status' => 'approved',
            'user_id' => $this->user->id,
        ]);

        $order = PurchaseOrder::create([
            'po_number' => 'PO-TEST-'.uniqid(),
            'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'requisition_id' => $requisition->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Head Office',
            'description' => 'Office stationery',
            'total_amount' => 12000,
            'status' => 'approved',
            'user_id' => $this->user->id,
            'approved_at' => now(),
            'approved_by' => $this->user->id,
        ]);

        $requisitionItem = RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'custom_description' => 'A4 paper, 10 reams',
            'expense_code_id' => ($code ?? $this->expenseCode())->id,
            'quantity' => 10,
            'unit_price' => 1200,
            'total' => 12000,
            'purpose' => 'Office use',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'requisition_item_id' => $requisitionItem->id,
            'custom_description' => 'A4 paper, 10 reams',
            'quantity' => 10,
            'unit_price' => 1200,
            'total' => 12000,
        ]);

        return $order->fresh();
    }
}
