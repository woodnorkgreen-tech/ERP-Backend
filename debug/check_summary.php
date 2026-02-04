<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$repo = app(App\Modules\Finance\PettyCash\Repositories\PettyCashRepository::class);
$summary = $repo->getTransactionSummary();

echo json_encode($summary, JSON_PRETTY_PRINT);
