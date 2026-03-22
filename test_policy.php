<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$budgetDataList = \App\Models\TaskBudgetData::take(5)->get();
foreach ($budgetDataList as $bd) {
    echo "ID: {$bd->id} | Status: {$bd->status} | Task ID: {$bd->enquiry_task_id}\n";
    echo "Summary: " . json_encode($bd->budget_summary) . "\n\n";
}
