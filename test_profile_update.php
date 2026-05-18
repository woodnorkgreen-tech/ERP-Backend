<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find(13); // Lydia
auth()->login($user);

$request = \Illuminate\Http\Request::create('/api/hr/profile/update-request', 'POST', [
    'reason' => 'Test update',
    'new_data' => [
        'first_name' => 'NEW_FIRST_NAME_TEST',
        'last_name' => 'NEW_LAST_NAME_TEST'
    ]
]);
$request->headers->set('Accept', 'application/json');

$controller = app()->make(\App\Modules\HR\Http\Controllers\HRActionController::class);
$response = $controller->requestProfileUpdate($request);

echo "Response Status: " . $response->getStatusCode() . "\n";
echo "Response Content: " . $response->getContent() . "\n";
