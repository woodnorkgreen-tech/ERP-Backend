<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$data = DB::table('task_budget_data')
    ->join('enquiry_tasks', 'task_budget_data.enquiry_task_id', '=', 'enquiry_tasks.id')
    ->select('enquiry_tasks.project_enquiry_id', 'task_budget_data.budget_summary')
    ->get();

foreach ($data as $item) {
    $summary = json_decode($item->budget_summary, true);
    $total = $summary['grandTotal'] ?? 0;
    echo "Enquiry ID: {$item->project_enquiry_id}, Total: {$total}\n";
}
