<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

// Mock Auth
if(!Auth::check()) {
    $user = \App\Models\User::first();
    if($user) {
        Auth::login($user);
        echo "Logged in as user ID: " . $user->id . "\n";
    } else {
        echo "No users found!\n";
        exit;
    }
}

try {
    DB::beginTransaction();
    echo "Starting transaction...\n";

    $items = [
        ['description' => 'Item 1', 'amount' => 50.00, 'payee_id' => null, 'payee_name' => 'John Doe'],
        ['description' => 'Item 2', 'amount' => 50.00, 'payee_id' => null, 'payee_name' => null]
    ];

    $total = 100.00;
    
    $commonData = [
        'user_id' => Auth::id(),
        'department_id' => 1,
        'category' => 'Testing',
        'purpose' => 'Test with items',
        'project_id' => null,
        'enquiry_id' => null,
        'status' => 'pending',
    ];

    $reqNum = PettyCashRequisition::generateRequisitionNumber();
    echo "Generated Requisition Number: $reqNum\n";

    $requisition = PettyCashRequisition::create(array_merge($commonData, [
        'requisition_number' => $reqNum,
        'payee_id' => null,
        'payee_name' => 'Main Payee',
        'total_amount' => $total,
    ]));

    echo "Requisition created ID: " . $requisition->id . "\n";

    foreach ($items as $item) {
        $requisition->items()->create([
            'description' => $item['description'],
            'amount' => $item['amount'],
            'payee_id' => $item['payee_id'] ?? null,
            'payee_name' => $item['payee_name'] ?? null,
        ]);
    }
    
    echo "Items created. Committing...\n";

    DB::commit();
    echo "Success!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
