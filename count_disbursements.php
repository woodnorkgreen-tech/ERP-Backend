<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "Total Count: " . App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::count() . "\n";
echo "Active Count: " . App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::where('status', 'active')->count() . "\n";
echo "Active Non-Archived Count: " . App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::where('status', 'active')->where('is_archived', false)->count() . "\n";
