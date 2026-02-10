<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();
    $r = PettyCashRequisition::create([
        'user_id' => 1,
        'department_id' => 1,
        'category' => 'Testing',
        'purpose' => 'Test',
        'total_amount' => 100,
        'requisition_number' => 'TEST-' . time()
    ]);
    echo "Success: " . $r->id . "\n";
    DB::rollBack();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
