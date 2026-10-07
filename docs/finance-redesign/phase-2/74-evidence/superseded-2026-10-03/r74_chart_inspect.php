<?php
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$db = DB::connection();
echo 'DATABASE: ' . $db->selectOne('SELECT DATABASE() AS db')->db . "\n\n";

echo "=== COS-002 EXISTS? ===\n";
$cos2 = $db->table('chart_of_accounts')->where('code','COS-002')->first();
echo ($cos2 ? "YES: id={$cos2->id} name={$cos2->name} postable={$cos2->is_postable} active={$cos2->is_active}\n" : "NOT FOUND\n");

echo "\n=== ALL COS-* ACCOUNTS ===\n";
$cosAccts = $db->table('chart_of_accounts')->where('code','like','COS-%')->orderBy('code')->get(['code','name','is_postable','is_active','account_type','normal_balance','parent_id']);
foreach($cosAccts as $a) { echo "{$a->code} | {$a->name} | postable={$a->is_postable} | active={$a->is_active} | type={$a->account_type} | balance={$a->normal_balance} | parent_id={$a->parent_id}\n"; }

echo "\n=== FIVE CONFLICT ACCOUNTS BY CODE ===\n";
foreach(['2110','1330','2120','2150','2200'] as $code) {
    $a = $db->table('chart_of_accounts')->where('code',$code)->first();
    echo ($a ? "{$a->code}: {$a->name} | postable={$a->is_postable} | active={$a->is_active} | type={$a->account_type} | nb={$a->normal_balance} | parent_id={$a->parent_id}" : "{$code}: NOT FOUND") . "\n";
}

echo "\n=== FIVE CONFLICT ACCOUNTS BY NAME ===\n";
foreach(['Output VAT Payable','Input VAT Recoverable','Withholding Tax Payable','Accrued Expenses','Client Deposits'] as $name) {
    $rows = $db->table('chart_of_accounts')->where('name',$name)->get(['code','name','is_postable','is_active','account_type','normal_balance']);
    foreach($rows as $a) { echo "{$a->code}: {$a->name} | postable={$a->is_postable} | active={$a->is_active} | type={$a->account_type} | nb={$a->normal_balance}\n"; }
    if($rows->isEmpty()) { echo "'{$name}': NOT FOUND\n"; }
}

echo "\n=== FULL CHART SUMMARY ===\n";
$total = $db->table('chart_of_accounts')->count();
$active_postable = $db->table('chart_of_accounts')->where('is_postable',1)->where('is_active',1)->count();
$missing_either = $db->table('chart_of_accounts')->where('is_postable',1)->where('is_active',1)
    ->where(function($q){ $q->whereNull('account_type')->orWhereNull('normal_balance'); })->count();
echo "Total: {$total} | Active+postable: {$active_postable} | Missing type or nb: {$missing_either}\n";

echo "\n=== ALL ACCOUNTS MISSING CLASSIFICATION ===\n";
$missing = $db->table('chart_of_accounts')->where('is_postable',1)->where('is_active',1)
    ->where(function($q){ $q->whereNull('account_type')->orWhereNull('normal_balance'); })
    ->orderBy('code')->get(['code','name','account_type','normal_balance']);
foreach($missing as $a) { echo "{$a->code} | {$a->name} | type={$a->account_type} | nb={$a->normal_balance}\n"; }
