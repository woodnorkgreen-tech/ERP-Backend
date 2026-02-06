<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- DEEP AUDIT START ---\n\n";

// 1. Check for specific amount 2530
$specialAmount = 2530;
$matching = PettyCashDisbursement::where('amount', $specialAmount)->get();
echo "Disbursements with amount $specialAmount:\n";
if ($matching->isEmpty()) {
    echo "None found.\n";
} else {
    foreach ($matching as $d) {
        echo "ID: {$d->id} | Status: {$d->status} | Archived: {$d->is_archived} | Project: {$d->project_name}\n";
    }
}
echo str_repeat("-", 40) . "\n";

// 2. Breakdown by Status and Archival
echo "BREAKDOWN BY STATUS:\n";
$breakdown = PettyCashDisbursement::select('status', 'is_archived', \Illuminate\Support\Facades\DB::raw('count(*) as count'), \Illuminate\Support\Facades\DB::raw('sum(amount) as total'))
    ->groupBy('status', 'is_archived')
    ->get();

foreach ($breakdown as $b) {
    echo "Status: " . str_pad($b->status, 10) . " | Archived: " . ($b->is_archived ? 'Y' : 'N') . " | Count: " . str_pad($b->count, 3) . " | Total: " . number_format($b->total, 2) . "\n";
}
echo str_repeat("-", 40) . "\n";

// 3. Top-up Remaining Balances
echo "TOP-UP REMAINING BALANCES:\n";
$topUps = PettyCashTopUp::all();
foreach ($topUps as $t) {
    echo "ID: {$t->id} | Amount: " . number_format($t->amount, 2) . " | Remaining: " . number_format($t->remaining_balance, 2) . "\n";
}
echo str_repeat("-", 40) . "\n";

// 4. Any discrepancy between model attribute and raw DB for 'amount'?
$disbursements = PettyCashDisbursement::all();
$rawSum = 0;
foreach ($disbursements as $d) {
    $rawSum += (float) $d->getRawOriginal('amount');
}
echo "Raw Sum (original DB values): " . number_format($rawSum, 2) . "\n";
echo "Eloquent Sum (sum('amount')): " . number_format(PettyCashDisbursement::sum('amount'), 2) . "\n";

// 5. Check taxonomies/labels that might hide 2530
echo "\n--- DEEP AUDIT END ---\n";
