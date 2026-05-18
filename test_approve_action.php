<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find(13); // Lydia
auth()->login($user);

$action = \App\Modules\HR\Models\HRAction::find(17); // the one we just created
if (!$action) {
    echo "Action not found\n";
    exit;
}

$request = \Illuminate\Http\Request::create("/api/hr/actions/{$action->id}/approve", 'PUT');
$request->headers->set('Accept', 'application/json');

$controller = app()->make(\App\Modules\HR\Http\Controllers\HRActionController::class);
$response = $controller->approveAction($request, $action->id);

echo "Response Status: " . $response->getStatusCode() . "\n";
echo "Response Content: " . $response->getContent() . "\n";

$employee = \App\Modules\HR\Models\Employee::find($action->employee_id);
echo "Employee First Name: " . $employee->first_name . "\n";
