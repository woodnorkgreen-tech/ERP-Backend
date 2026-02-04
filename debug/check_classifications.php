<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$classifications = App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::distinct()->pluck('classification');
echo "Unique Classifications in DB:\n";
print_r($classifications->toArray());
