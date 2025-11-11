<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking if task_budget_data exists for enquiry_task_id 4:\n";

$budgetData = DB::table('task_budget_data')->where('enquiry_task_id', 4)->first();

if ($budgetData) {
    echo "Budget data found:\n";
    print_r($budgetData);
} else {
    echo "Budget data not found for task 4!\n";
}

echo "\nChecking all task_budget_data:\n";
$allBudgetData = DB::table('task_budget_data')->get();
foreach($allBudgetData as $bd) {
    echo "ID: {$bd->id}, enquiry_task_id: {$bd->enquiry_task_id}\n";
}
