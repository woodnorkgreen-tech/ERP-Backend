<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Modules\HR\Models\Employee;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Update John Manager (EMP001)
$employee = Employee::where('employee_id', 'EMP001')->first();

if ($employee) {
    echo "Updating Employee: " . $employee->name . "\n";
    $employee->update([
        'id_number' => '12345678',
        'kra_pin' => 'A001234567Z',
        'nssf_id' => 'NSSF001',
        'nhif_id' => 'NHIF001',
        'bank_name' => 'KCB Bank',
        'bank_branch' => 'Nairobi Central',
        'account_number' => '1100223344',
        'is_on_probation' => false
    ]);
    echo "Update Successful!\n";
} else {
    echo "Employee EMP001 not found.\n";
}
