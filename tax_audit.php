<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "ID | AMOUNT | TAX | TOTAL | STATUS | ARCHIVED | PROJECT\n";
echo str_repeat("-", 80) . "\n";
$all = PettyCashDisbursement::orderBy('id')->get();
$sumAmt = 0;
$sumTax = 0;

foreach ($all as $d) {
    // Check if 'tax' column exists and get its value
    $tax = (float) ($d->tax ?? 0); 
    $total = $d->amount + $tax;
    
    printf("%-4d | %8.2f | %8.2f | %8.2f | %-8s | %d | %s\n", 
        $d->id, $d->amount, $tax, $total, $d->status, $d->is_archived, $d->project_name);
    
    if ($d->status === 'active' && !$d->is_archived) {
        $sumAmt += $d->amount;
        $sumTax += $tax;
    }
}

echo str_repeat("-", 80) . "\n";
echo "Active Non-Archived Sum (Amt): " . number_format($sumAmt, 2) . "\n";
echo "Active Non-Archived Sum (Tax): " . number_format($sumTax, 2) . "\n";
echo "Active Non-Archived Sum (Total): " . number_format($sumAmt + $sumTax, 2) . "\n";
echo "Total Count: " . $all->count() . "\n";
