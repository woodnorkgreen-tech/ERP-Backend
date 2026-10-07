<?php
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$db = DB::connection();

// Accounting periods
echo "=== ACCOUNTING PERIODS ===\n";
$cols = $db->select('SHOW COLUMNS FROM accounting_periods');
echo 'Columns: ';
foreach($cols as $c) { echo $c->Field . ','; }
echo "\n";
$periods = $db->table('accounting_periods')->orderByDesc('id')->limit(6)->get();
foreach($periods as $p) { echo json_encode($p) . "\n"; }

// Document sequences
echo "\n=== DOCUMENT SEQUENCES ===\n";
try {
    $scols = $db->select('SHOW COLUMNS FROM document_sequences');
    echo 'Columns: ';
    foreach($scols as $c) { echo $c->Field . ','; }
    echo "\n";
    $seqs = $db->table('document_sequences')->orderBy('id')->get();
    if($seqs->isEmpty()) { echo "TABLE EMPTY\n"; }
    foreach($seqs as $s) { echo json_encode($s) . "\n"; }
} catch(\Exception $e) { echo "NOT FOUND: " . $e->getMessage() . "\n"; }

// Journal usage on five conflict accounts
echo "\n=== JOURNAL USAGE ON 5 CONFLICT ACCOUNTS ===\n";
foreach(['2110','1330','2120','2150','2200'] as $code) {
    $a = $db->table('chart_of_accounts')->where('code', $code)->first();
    if(!$a) { echo "$code: NOT FOUND\n"; continue; }
    $lines = $db->table('journal_lines')->where('account_id', $a->id)->count();
    $bal = $db->table('journal_lines')->where('account_id', $a->id)
        ->selectRaw('SUM(debit_amount - credit_amount) as net')->first();
    $net = $bal->net ?? 0;
    echo "$code {$a->name}: {$lines} lines | net={$net}\n";
}

// WNG function map resolution
echo "\n=== FUNCTION MAP (profile=wng, wip=capitalise) ===\n";
$map = \App\Modules\Finance\Support\FinanceChartProfile::map('wng', 'capitalise');
$functions = \App\Modules\Finance\Support\FinanceAccountFunctions::all();
foreach($functions as $key => $f) {
    $ref  = $f['code'];
    $local = $map[$ref] ?? $ref; // falls back to ref if not mapped
    $a = $db->table('chart_of_accounts')->where('code', $local)->first();
    $mapped = array_key_exists($ref, $map);
    $status = $a ? ($a->is_postable && $a->is_active ? 'RESOLVED' : 'EXISTS_NOT_POSTABLE') : 'MISSING';
    echo sprintf("%-30s ref=%-6s -> local=%-12s %s | %s\n",
        $key, $ref, $local,
        $mapped ? 'MAPPED' : 'UNMAPPED',
        $status . ($a ? " ({$a->name})" : ''));
}
$resolved = 0;
foreach($functions as $key => $f) {
    $ref = $f['code'];
    $local = $map[$ref] ?? $ref;
    $a = $db->table('chart_of_accounts')->where('code', $local)->first();
    if($a && $a->is_postable && $a->is_active) { $resolved++; }
}
echo "\nTotal resolved: {$resolved} / " . count($functions) . "\n";

// Tax reference config
echo "\n=== TAX CONFIGURATION ===\n";
try {
    $taxTypes = $db->table('tax_types')->orderBy('code')->get();
    foreach($taxTypes as $t) { echo json_encode($t) . "\n"; }
} catch(\Exception $e) { echo "tax_types not found: " . $e->getMessage() . "\n"; }

// Expense codes full summary
echo "\n=== EXPENSE CODES FULL SUMMARY ===\n";
try {
    $codes = $db->table('expense_codes')->orderBy('code')->get();
    $ok=0; $fail=0; $inactive=0;
    foreach($codes as $c) {
        if(!$c->is_active) { $inactive++; continue; }
        $acct = $c->account_id ? $db->table('chart_of_accounts')->where('id', $c->account_id)->first(['code','name','is_postable','is_active']) : null;
        $status = $acct ? ($acct->is_postable && $acct->is_active ? 'OK' : 'ACCT_INACTIVE') : 'NO_ACCOUNT';
        if($status === 'OK') { $ok++; } else { $fail++; echo "FAIL {$c->code}: {$status} acct_id={$c->account_id}\n"; }
    }
    echo "Active expense codes: OK={$ok} FAIL={$fail} | Inactive codes: {$inactive}\n";
} catch(\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
