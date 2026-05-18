<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $comp = App\Modules\HR\Models\Compensation::find(4);
    if (!$comp) die("Compensation 4 not found\n");
    $service = app(App\Modules\HR\Services\OvertimeService::class);
    // auth user as admin
    $user = App\Models\User::first();
    auth()->login($user);
    $service->hrApproveCompensation($comp);
    echo "Success\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
