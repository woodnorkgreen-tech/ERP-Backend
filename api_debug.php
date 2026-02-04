<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;

$repo = app(PettyCashRepository::class);

echo "--- REPOSITORY SUMMARY REPORT ---\n\n";

$summary = $repo->getTransactionSummary([]);
echo "NO FILTERS:\n";
print_r($summary);

echo "\n--- BY CLASSIFICATION ---\n";
$classifications = ['admin', 'agencies', 'operations', 'event_planners', 'corporates', 'crs', 'other'];
foreach ($classifications as $class) {
    $s = $repo->getTransactionSummary(['classification' => $class]);
    if ($s['total_disbursements'] > 0) {
        echo "Class: $class | Total: " . number_format($s['total_disbursements'], 2) . "\n";
    }
}

echo "\n--- BY PROJECT ---\n";
$projects = \App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::active()->distinct()->pluck('project_name');
foreach ($projects as $proj) {
    if (empty($proj)) continue;
    $s = $repo->getTransactionSummary(['project_name' => $proj]);
    echo "Project: " . str_limit($proj, 30) . " | Total: " . number_format($s['total_disbursements'], 2) . "\n";
}

echo "\n--- REPORT FINISHED ---\n";

function str_limit($value, $limit = 100, $end = '...') {
    if (mb_strwidth($value, 'UTF-8') <= $limit) return $value;
    return mb_strimwidth($value, 0, $limit, $end, 'UTF-8');
}
