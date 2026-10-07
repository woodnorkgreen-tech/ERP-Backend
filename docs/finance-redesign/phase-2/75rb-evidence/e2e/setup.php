<?php
/*
 * Report 75R-B end-to-end: people, permissions and reference data for a
 * disposable test database. Refuses to run against anything else.
 */
use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\HR\Models\Employee;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

$db = DB::connection()->getDatabaseName();
if ($db !== 'db_scratch_test') {
    fwrite(STDERR, "REFUSED: connected to {$db}, not the disposable test database.\n");
    exit(1);
}
foreach (['FinanceDimensionSeeder', 'AccountingPeriodSeeder', 'ChartOfAccountSeeder', 'PaymentSourceSeeder', 'ExpenseCodeSeeder'] as $seeder) {
    (new ("App\\Modules\\Finance\\Database\\Seeders\\{$seeder}"))->run();
}
$department = DB::table('departments')->insertGetId(['name' => 'Events', 'created_at' => now(), 'updated_at' => now()]);
$person = function (string $name, string $email, array $permissions, ?int $employeeId = null) use ($department) {
    $user = User::factory()->create(['name' => $name, 'email' => $email, 'password' => Hash::make('E2e-75rb-pass'), 'is_active' => true,
        'department_id' => $department, 'employee_id' => $employeeId]);
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
};
$view = [Permissions::FINANCE_PETTY_CASH_VIEW];
$steve = Employee::create(['employee_id' => 'E2E-001', 'first_name' => 'Steve', 'last_name' => 'Otieno', 'department_id' => $department,
    'position' => 'Site lead', 'hire_date' => '2025-01-01', 'status' => 'active']);
$supplier = Supplier::create(['supplier_name' => 'Winnie Supplies', 'contact_person' => 'Winnie', 'phone' => '0700000000', 'email' => 'winnie@example.test']);
$people = [
    'creator' => $person('Rita Wanjiru', 'rita@e2e.test', $view),
    'verifier' => $person('Winnie Achieng', 'verifier@e2e.test', [...$view, Permissions::FINANCE_REQUISITIONS_VERIFY]),
    'approver' => $person('Finance Approver', 'approver@e2e.test', [...$view, Permissions::FINANCE_PETTY_CASH_UPDATE, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS]),
    'cashier' => $person('Finance Cashier', 'cashier@e2e.test', [...$view, Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS]),
    'controller' => $person('Finance Controller', 'controller@e2e.test', [...$view, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
        Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, Permissions::FINANCE_PAYMENTS_REVERSE, Permissions::FINANCE_JOURNALS_REVERSE]),
    'steve' => $person('Steve Otieno', 'steve@e2e.test', $view, $steve->id),
];
$type = PettyCashRequisitionType::create(['code' => 'event-group', 'name' => 'Event group payment', 'recipient_mode' => 'per_item',
    'is_active' => true, 'requires_project' => false, 'request_fields' => [], 'item_fields' => []]);

echo json_encode([
    'database' => $db, 'department_id' => $department, 'type_id' => $type->id, 'type_name' => $type->name,
    'employee_id' => $steve->id, 'supplier_id' => $supplier->id,
    'users' => array_map(fn ($u) => ['id' => $u->id, 'email' => $u->email, 'name' => $u->name], $people),
    'expense_code_id' => (int) DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->where('is_active', true)->value('id'),
    'sources' => DB::table('payment_sources')->whereNotNull('gl_account_id')->whereIn('type', ['bank', 'mobile_money'])->orderBy('id')->limit(2)->get(['id', 'name', 'type']),
], JSON_PRETTY_PRINT)."\n";
