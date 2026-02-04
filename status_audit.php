<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- UNIQUE STATUSES IN DISBURSEMENTS ---\n\n";

$statuses = DB::table('petty_cash_disbursements')
    ->select('status', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
    ->groupBy('status')
    ->get();

foreach ($statuses as $s) {
    echo "Status: " . str_pad($s->status, 15) . " | Count: " . str_pad($s->count, 5) . " | Total: " . number_format($s->total, 2) . "\n";
}

echo "\n--- UNIQUE IS_ARCHIVED VALUES ---\n\n";

$archived = DB::table('petty_cash_disbursements')
    ->select('is_archived', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
    ->groupBy('is_archived')
    ->get();

foreach ($archived as $a) {
    echo "Archived: " . ($a->is_archived ? 'Y' : 'N') . " | Count: " . str_pad($a->count, 5) . " | Total: " . number_format($a->total, 2) . "\n";
}

echo "\n--- SYSTEM BALANCE ---\n";
$balance = DB::table('petty_cash_balances')->first();
print_r((array)$balance);

echo "\n--- TOTAL TOP-UPS ---\n";
$totalTopUps = DB::table('petty_cash_top_ups')->sum('amount');
echo "Total: " . number_format($totalTopUps, 2) . "\n";

echo "\n--- SUMMARY ---\n";
echo "Total Disbursements (All): " . number_format(DB::table('petty_cash_disbursements')->sum('amount'), 2) . "\n";
echo "Expected Balance (Topups - All Disbursements): " . number_format($totalTopUps - DB::table('petty_cash_disbursements')->sum('amount'), 2) . "\n";
echo "Actual Table Balance: " . number_format($balance->current_balance ?? 0, 2) . "\n";
