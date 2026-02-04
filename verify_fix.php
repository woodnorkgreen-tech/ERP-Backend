<?php

use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$repo = app(PettyCashRepository::class);

function testSummaryAndListConsistency($filters = []) {
    global $repo;
    $summary = $repo->getTransactionSummary($filters);
    $list = $repo->getFlatTransactions($filters, 100);
    
    $summaryDisbursements = $summary['total_disbursements'];
    $listDisbursements = $list->where('type', 'disbursement')->where('status', 'active')->sum('amount');
    
    echo "Filters: " . json_encode($filters) . "\n";
    echo "Summary Total: " . number_format($summaryDisbursements, 2) . "\n";
    echo "List Total:    " . number_format($listDisbursements, 2) . "\n";
    
    if (abs($summaryDisbursements - $listDisbursements) < 0.01) {
        echo "✅ MATCH\n";
    } else {
        echo "❌ MISMATCH (Diff: " . ($summaryDisbursements - $listDisbursements) . ")\n";
    }
    echo str_repeat("-", 30) . "\n";
}

echo "--- VERIFICATION START ---\n\n";

// 1. Base case
testSummaryAndListConsistency([]);

// 2. Test project filter (if any data)
$firstProject = PettyCashDisbursement::active()->whereNotNull('project_name')->first();
if ($firstProject) {
    testSummaryAndListConsistency(['project_name' => $firstProject->project_name]);
} else {
    echo "Skipping project filter test (no projects found in active disbursements)\n";
}

// 3. Test archival (Experimental: Archive one and check)
$disbursement = PettyCashDisbursement::active()->notArchived()->first();
if ($disbursement) {
    echo "Archiving disbursement ID: " . $disbursement->id . " (Amount: " . $disbursement->amount . ")\n";
    $disbursement->update(['is_archived' => true]);
    
    testSummaryAndListConsistency([]);
    
    echo "Restoring disbursement ID: " . $disbursement->id . "\n";
    $disbursement->update(['is_archived' => false]);
    echo str_repeat("-", 30) . "\n";
}

// 4. Recalculate Balance check
$balance = PettyCashBalance::current();
$oldVal = $balance->current_balance;
$balance->recalculateBalance();
$newVal = $balance->current_balance;
echo "Balance Recalculation:\n";
echo "Old Balance: " . number_format($oldVal, 2) . "\n";
echo "New Balance: " . number_format($newVal, 2) . "\n";
if (abs($oldVal - $newVal) < 0.01) {
    echo "✅ BALANCE STABLE\n";
} else {
    echo "⚠️ BALANCE ADJUSTED by " . ($newVal - $oldVal) . "\n";
}

echo "\n--- VERIFICATION END ---\n";
