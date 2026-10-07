<?php
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$db = DB::connection();

// WHT categories
echo "=== WHT_CATEGORIES ===\n";
$wht = $db->table('wht_categories')->get();
foreach($wht as $w) { echo json_encode($w) . "\n"; }

// VAT treatments
echo "\n=== VAT_TREATMENTS ===\n";
$vat = $db->table('vat_treatments')->get();
foreach($vat as $v) { echo json_encode($v) . "\n"; }

// Expense codes: resolve default_debit_account_id
echo "\n=== EXPENSE CODE ACCOUNT RESOLUTION ===\n";
$codes = $db->table('expense_codes')->where('is_active',1)->orderBy('code')->get();
$ok=0; $fail=0;
foreach($codes as $c) {
    $acct = $c->default_debit_account_id
        ? $db->table('chart_of_accounts')->where('id', $c->default_debit_account_id)->first(['id','code','name','is_postable','is_active'])
        : null;
    $status = $acct ? ($acct->is_postable && $acct->is_active ? 'OK' : 'INACTIVE_OR_NOT_POSTABLE') : 'NO_ACCOUNT';
    if($status === 'OK') { $ok++; }
    else { $fail++; echo "FAIL {$c->code}: acct_id={$c->default_debit_account_id} {$status} acct=" . ($acct ? "{$acct->code} {$acct->name}" : 'null') . "\n"; }
}
echo "Active codes: OK={$ok} FAIL={$fail}\n";
$inactive = $db->table('expense_codes')->where('is_active',0)->count();
echo "Inactive codes: {$inactive}\n";
echo "Total: " . $db->table('expense_codes')->count() . "\n";

// Parent accounts for COS-021/022/023 proposal
echo "\n=== COS-002 DETAILS (parent for COS-021/022/023) ===\n";
$cos2 = $db->table('chart_of_accounts')->where('code','COS-002')->first();
if($cos2) {
    echo "COS-002: id={$cos2->id} postable={$cos2->is_postable} active={$cos2->is_active} type={$cos2->account_type} nb={$cos2->normal_balance}\n";
    echo "PROBLEM: COS-002 is_postable=1 — the CompleteChartCommand refuses a new child whose parent is postable (it expects parents to be header/non-postable)\n";
    echo "Resolution: COS-002 must either be made non-postable OR the profile must use a different existing non-postable header\n";
}

// Check for any existing non-postable COS accounts
echo "\n=== NON-POSTABLE COS ACCOUNTS ===\n";
$headers = $db->table('chart_of_accounts')->where('code','like','COS-%')->where('is_postable',0)->get(['code','name','is_postable','is_active']);
if($headers->isEmpty()) { echo "NONE — no non-postable COS header exists\n"; }
foreach($headers as $h) { echo "{$h->code}: {$h->name} postable={$h->is_postable} active={$h->is_active}\n"; }

// All ERP-added accounts (numeric codes added by migration)
echo "\n=== ERP-ADDED ACCOUNTS (numeric codes in WNG chart) ===\n";
$erpAdded = $db->table('chart_of_accounts')
    ->whereRaw("code REGEXP '^[0-9]+$'")
    ->orderBy('code')
    ->get(['code','name','account_type','normal_balance','is_postable','is_active','parent_id']);
foreach($erpAdded as $a) { echo "{$a->code} | {$a->name} | type={$a->account_type} | nb={$a->normal_balance} | postable={$a->is_postable} | active={$a->is_active}\n"; }
