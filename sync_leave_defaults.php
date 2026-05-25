<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var \App\Modules\HR\Services\LeaveManagementService $service */
$service = $app->make(\App\Modules\HR\Services\LeaveManagementService::class);
$service->syncDefaultLeaveTypes();

echo "Leave defaults synced\n";
