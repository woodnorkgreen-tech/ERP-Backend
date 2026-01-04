<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- QUOTE DATA ---\n";
$quotes = DB::table('task_quote_data')
    ->join('enquiry_tasks', 'task_quote_data.enquiry_task_id', '=', 'enquiry_tasks.id')
    ->select('task_quote_data.*', 'enquiry_tasks.project_enquiry_id')
    ->get();

foreach ($quotes as $q) {
    echo "ID: {$q->id}, Enquiry: {$q->project_enquiry_id}, Status: {$q->status}, Amount: {$q->quote_amount}\n";
}
