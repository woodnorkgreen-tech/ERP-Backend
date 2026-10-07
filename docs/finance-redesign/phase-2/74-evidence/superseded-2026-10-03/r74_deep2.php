<?php
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$db = DB::connection();

// Discover journal_lines columns
echo "=== JOURNAL_LINES COLUMNS ===\n";
$jcols = $db->select('SHOW COLUMNS FROM journal_lines');
foreach($jcols as $c) { echo $c->Field . " " . $c->Type . "\n"; }

// Journal usage on five conflict accounts
echo "\n=== JOURNAL USAGE ON 5 CONFLICT ACCOUNTS ===\n";
foreach(['2110','1330','2120','2150','2200'] as $code) {
    $a = $db->table('chart_of_accounts')->where('code', $code)->first();
    if(!$a) { echo "$code: NOT FOUND\n"; continue; }
    $lines = $db->table('journal_lines')->where('account_id', $a->id)->count();
    // Try common column names
    $amtCol = null;
    foreach($jcols as $c) {
        if(in_array($c->Field, ['amount','debit','credit','debit_amount','credit_amount','signed_amount'])) {
            $amtCol = $c->Field; break;
        }
    }
    echo "$code {$a->name}: {$lines} journal lines | amount_col={$amtCol}\n";
    // Show most recent 3 journal entries for evidence
    $recent = $db->table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->where('jl.account_id', $a->id)
        ->orderByDesc('je.id')
        ->limit(3)
        ->get(['je.id as je_id','je.description','je.posted_at']);
    foreach($recent as $r) { echo "  je#{$r->je_id} {$r->description} | posted={$r->posted_at}\n"; }
}

// Function map resolution
echo "\n=== FUNCTION MAP (profile=wng, wip=capitalise) ===\n";
$map = \App\Modules\Finance\Support\FinanceChartProfile::map('wng', 'capitalise');
$functions = \App\Modules\Finance\Support\FinanceAccountFunctions::all();
$resolved = 0; $unresolved = [];
foreach($functions as $key => $f) {
    $ref   = $f['code'];
    $local = $map[$ref] ?? null;
    $a     = $local ? $db->table('chart_of_accounts')->where('code', $local)->first() : null;
    $ok    = $a && $a->is_postable && $a->is_active;
    if($ok) { $resolved++; } else { $unresolved[] = $key; }
    $mapped = array_key_exists($ref, $map);
    $note  = $a ? ($a->name . " | postable={$a->is_postable} | active={$a->is_active}") : 'MISSING';
    echo sprintf("%-30s ref=%-6s local=%-12s %s | %s\n", $key, $ref, $local ?? '(none)', $mapped ? 'MAPPED' : 'UNMAPPED', $ok ? "RESOLVED: {$note}" : "UNRESOLVED: {$note}");
}
echo "\nResolved: {$resolved} / " . count($functions) . "\n";
echo "Unresolved: " . implode(', ', $unresolved) . "\n";

// Tax types
echo "\n=== TAX CONFIGURATION ===\n";
try {
    $taxTypes = $db->table('tax_types')->orderBy('code')->get();
    foreach($taxTypes as $t) { echo json_encode($t) . "\n"; }
} catch(\Exception $e) { echo "tax_types: " . $e->getMessage() . "\n"; }
try {
    $taxRates = $db->table('tax_rates')->orderBy('code')->limit(20)->get();
    foreach($taxRates as $t) { echo json_encode($t) . "\n"; }
} catch(\Exception $e) { echo "tax_rates: " . $e->getMessage() . "\n"; }

// Expense codes full
echo "\n=== EXPENSE CODES ===\n";
try {
    $codes = $db->table('expense_codes')->orderBy('code')->get();
    $ok2=0; $fail2=0; $inactive2=0;
    foreach($codes as $c) {
        if(!$c->is_active) { $inactive2++; continue; }
        $acct = $c->account_id ? $db->table('chart_of_accounts')->where('id', $c->account_id)->first(['code','name','is_postable','is_active']) : null;
        $status = $acct ? ($acct->is_postable && $acct->is_active ? 'OK' : 'INACTIVE') : 'NO_ACCOUNT';
        if($status === 'OK') { $ok2++; } else { $fail2++; echo "FAIL {$c->code}: acct_id={$c->account_id} {$status}\n"; }
    }
    echo "Active OK={$ok2} FAIL={$fail2} | Inactive={$inactive2}\n";
} catch(\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
