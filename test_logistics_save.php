<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Modules\logisticsTask\Services\LogisticsTaskService;

echo "=== Testing Logistics Save ===\n\n";

// Set a fake authenticated user
auth()->loginUsingId(1);

$service = new LogisticsTaskService();
$taskId = 38;

$data = [
    'vehicle_type' => 'Truck',
    'vehicle_identification' => 'KAA 123X',
    'driver_name' => 'John Doe',
    'driver_contact' => '0712345678',
];

try {
    echo "Attempting to save logistics planning for task {$taskId}...\n\n";

    $result = $service->saveLogisticsPlanning($taskId, $data);

    echo "SUCCESS!\n";
    echo "Logistics Task ID: {$result->id}\n";
    echo "Task ID: {$result->task_id}\n";
    echo "Project ID: {$result->project_id}\n";

} catch (\Exception $e) {
    echo "FAILED!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
