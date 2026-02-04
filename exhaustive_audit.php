<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "ID | AMOUNT | STATUS | ARCHIVED | PROJECT\n";
echo str_repeat("-", 60) . "\n";
$all = PettyCashDisbursement::orderBy('id')->get();
$sumActiveNonArchived = 0;
$sumActiveArchived = 0;
$sumVoided = 0;

foreach ($all as $d) {
    printf("%-4d | %8.2f | %-8s | %d | %s\n", $d->id, $d->amount, $d->status, $d->is_archived, $d->project_name);
    
    if ($d->status === 'active') {
        if ($d->is_archived) {
            $sumActiveArchived += $d->amount;
        } else {
            $sumActiveNonArchived += $d->amount;
        }
    } else {
        $sumVoided += $d->amount;
    }
}

echo str_repeat("-", 60) . "\n";
echo "Active Non-Archived Sum: " . number_format($sumActiveNonArchived, 2) . "\n";
echo "Active Archived Sum:     " . number_format($sumActiveArchived, 2) . "\n";
echo "Voided Sum:              " . number_format($sumVoided, 2) . "\n";
echo "Total Count:             " . $all->count() . "\n";
