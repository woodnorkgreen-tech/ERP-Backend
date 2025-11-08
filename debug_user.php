<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== USERS ===\n";
$users = DB::table('users')->select('id', 'name', 'email', 'department_id')->get();
foreach($users as $user) {
    echo $user->id . ': ' . $user->name . ' (' . $user->email . ') - Dept: ' . ($user->department_id ?? 'None') . "\n";
}

echo "\n=== DEPARTMENTS ===\n";
$departments = DB::table('departments')->select('id', 'name')->get();
foreach($departments as $dept) {
    echo $dept->id . ': ' . $dept->name . "\n";
}

echo "\n=== PROJECT ENQUIRIES ===\n";
$enquiries = DB::table('project_enquiries')->select('id', 'title', 'department_id', 'status')->get();
foreach($enquiries as $enquiry) {
    echo $enquiry->id . ': ' . $enquiry->title . ' - Dept: ' . ($enquiry->department_id ?? 'None') . ' - Status: ' . $enquiry->status . "\n";
}

echo "\n=== ENQUIRY TASKS ===\n";
$tasks = DB::table('enquiry_tasks')->select('id', 'title', 'project_enquiry_id', 'status', 'department_id')->get();
foreach($tasks as $task) {
    echo $task->id . ': ' . $task->title . ' - Enquiry: ' . $task->project_enquiry_id . ' - Status: ' . $task->status . ' - Dept: ' . ($task->department_id ?? 'None') . "\n";
}

echo "\n=== DEPARTMENTAL TASKS ===\n";
$deptTasks = DB::table('enquiry_tasks')->select('id', 'project_enquiry_id', 'department_id', 'status', 'assigned_user_id', 'title')->get();
foreach($deptTasks as $task) {
    echo $task->id . ': Enquiry: ' . $task->project_enquiry_id . ' - Dept: ' . $task->department_id . ' - Status: ' . $task->status . ' - Assigned: ' . ($task->assigned_user_id ?? 'None') . "\n";
}
