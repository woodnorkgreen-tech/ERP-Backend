<?php
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$db = DB::connection();

echo "=== PAYMENT SOURCES ===\n";
$sources = $db->table('payment_sources')->orderBy('code')->get();
foreach($sources as $s) {
    $acct = $s->gl_account_id ? $db->table('chart_of_accounts')->where('id',$s->gl_account_id)->first(['code','name','account_type','is_postable','is_active']) : null;
    $type = $s->type ?? '?';
    echo "{$s->code} | {$s->name} | type={$type} | active={$s->is_active} | gl_id={$s->gl_account_id} | acct=" . ($acct ? "{$acct->code} {$acct->name} accttype={$acct->account_type} postable={$acct->is_postable} active={$acct->is_active}" : 'UNLINKED') . "\n";
}

echo "\n=== ACCOUNT id=128 (MPESA ref from Report 73) ===\n";
$a128 = $db->table('chart_of_accounts')->where('id',128)->first();
echo ($a128 ? "code={$a128->code} name={$a128->name} type={$a128->account_type} nb={$a128->normal_balance} postable={$a128->is_postable} active={$a128->is_active}" : 'NOT FOUND') . "\n";

echo "\n=== FINANCE SETTINGS ===\n";
try {
    $settings = $db->table('finance_settings')->get();
    if($settings->isEmpty()) { echo "TABLE EMPTY\n"; }
    foreach($settings as $s) { echo "{$s->key}: " . (is_null($s->value) ? 'NULL' : "'{$s->value}'") . "\n"; }
} catch(\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== ACCOUNTING PERIODS (latest 6) ===\n";
$periods = $db->table('accounting_periods')->orderBy('start_date','desc')->limit(6)->get();
foreach($periods as $p) {
    $locked = $p->is_locked ?? '?';
    echo "{$p->name} | {$p->start_date} -> {$p->end_date} | status={$p->status} | locked={$locked}\n";
}

echo "\n=== DOCUMENT SEQUENCES ===\n";
try {
    $seqs = $db->table('document_sequences')->orderBy('prefix')->get();
    if($seqs->isEmpty()) { echo "TABLE EMPTY\n"; }
    foreach($seqs as $s) {
        $type = $s->type ?? ($s->module ?? '?');
        echo "prefix={$s->prefix} | next={$s->next_number} | type={$type}\n";
    }
} catch(\Exception $e) { echo "table not found: " . $e->getMessage() . "\n"; }

echo "\n=== WIP / PROFILE CONFIG ===\n";
echo "FINANCE_ACCOUNT_PROFILE env: " . (getenv('FINANCE_ACCOUNT_PROFILE') ?: 'not set') . "\n";
echo "FINANCE_WIP_POLICY env: " . (getenv('FINANCE_WIP_POLICY') ?: 'not set') . "\n";
echo "config finance_accounts.profile: " . (config('finance_accounts.profile') ?: 'not set') . "\n";
echo "config finance_accounts.wip_policy: " . (config('finance_accounts.wip_policy') ?: 'not set') . "\n";

echo "\n=== EXPENSE CODES — ACTIVE, ACCOUNT RESOLUTION ===\n";
try {
    $codes = $db->table('expense_codes')->where('is_active',1)->orderBy('code')->get();
    $unresolved = 0; $resolved = 0;
    foreach($codes as $c) {
        $acct = isset($c->account_id) && $c->account_id ? $db->table('chart_of_accounts')->where('id',$c->account_id)->first(['code','name','is_postable','is_active']) : null;
        $status = $acct ? ($acct->is_postable && $acct->is_active ? 'OK' : 'NOT_POSTABLE_OR_INACTIVE') : 'UNRESOLVED';
        if($status !== 'OK') { $unresolved++; echo "FAIL {$c->code} | account_id=" . ($c->account_id ?? 'null') . " | {$status}\n"; }
        else { $resolved++; }
    }
    echo "Resolved: {$resolved} | Unresolved/inactive: {$unresolved}\n";
} catch(\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== POSTING FUNCTION RESOLUTION (FinanceAccountFunctions) ===\n";
$resolution = \App\Modules\Finance\Support\FinanceAccountFunctions::resolution();
$ok=0; $fail=0;
foreach($resolution as $key => $r) {
    if(!$r['resolved']) { $fail++; echo "FAIL {$key}: ref={$r['code']} local={$r['local_code']} | " . ($r['account'] ?? 'NO ACCOUNT') . "\n"; }
    else { $ok++; }
}
echo "Resolved: {$ok} / " . count($resolution) . " | Unresolved: {$fail}\n";
