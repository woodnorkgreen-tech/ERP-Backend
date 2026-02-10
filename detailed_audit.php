<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;

$repo = app(PettyCashRepository::class);

echo "--- PROJECT SUMS ---\n\n";

$projects = \App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::active()->distinct()->pluck('project_name');
foreach ($projects as $proj) {
    if (empty($proj)) {
        $projName = "NO PROJECT";
        $filters = ['project_name' => null];
    } else {
        $projName = $proj;
        $filters = ['project_name' => $proj];
    }
    
    // We can't pass null for project_name to the repo as it usually expects a string or empty
    $s = $repo->getTransactionSummary($filters);
    echo "Project: " . str_pad($projName, 40) . " | Total: " . number_format($s['total_disbursements'], 2) . "\n";
}

echo "\n--- BY CLASSIFICATION ---\n";
$classes = \App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::active()->distinct()->pluck('classification');
foreach ($classes as $class) {
    $s = $repo->getTransactionSummary(['classification' => $class]);
    echo "Class: " . str_pad($class ?? 'NULL', 40) . " | Total: " . number_format($s['total_disbursements'], 2) . "\n";
}
