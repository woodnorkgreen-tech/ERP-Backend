<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$items = PettyCashDisbursement::active()->notArchived()->orderBy('id')->get(['id', 'amount'])->toArray();
$targetGap = 2530;
$targetTotal = 11853;

echo "Searching for combinations that sum to $targetGap (the gap) or $targetTotal (the reported total)...\n\n";

function findCombinations($items, $target) {
    $results = [];
    $n = count($items);
    for ($i = 0; $i < (1 << $n); $i++) {
        $sum = 0;
        $currentSet = [];
        for ($j = 0; $j < $n; $j++) {
            if (($i >> $j) & 1) {
                $sum += (float)$items[$j]['amount'];
                $currentSet[] = $items[$j];
            }
        }
        if (abs($sum - $target) < 0.01) {
            $results[] = $currentSet;
        }
    }
    return $results;
}

$gapMatches = findCombinations($items, $targetGap);
echo "Found " . count($gapMatches) . " combinations summing to $targetGap:\n";
foreach ($gapMatches as $idx => $set) {
    echo "Match " . ($idx + 1) . ": IDs (" . implode(', ', array_column($set, 'id')) . ")\n";
}

echo "\n";

$totalMatches = findCombinations($items, $targetTotal);
echo "Found " . count($totalMatches) . " combinations summing to $targetTotal:\n";
foreach ($totalMatches as $idx => $set) {
    echo "Match " . ($idx + 1) . ": IDs (" . implode(', ', array_column($set, 'id')) . ")\n";
}
