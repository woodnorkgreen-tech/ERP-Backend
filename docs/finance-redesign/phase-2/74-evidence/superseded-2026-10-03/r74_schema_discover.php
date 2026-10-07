<?php
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$db = DB::connection();

// Discover expense_codes columns
echo "=== EXPENSE_CODES COLUMNS ===\n";
try {
    $cols = $db->select('SHOW COLUMNS FROM expense_codes');
    foreach($cols as $c) { echo $c->Field . " " . $c->Type . "\n"; }
    $sample = $db->table('expense_codes')->limit(3)->get();
    foreach($sample as $r) { echo json_encode($r) . "\n"; }
} catch(\Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

// Find all finance-related tables
echo "\n=== FINANCE TABLES (all) ===\n";
$tables = $db->select("SHOW TABLES LIKE '%finance%'");
foreach($tables as $t) { $v = array_values((array)$t)[0]; echo $v . "\n"; }

// Wht / tax tables
echo "\n=== WHT / TAX TABLES ===\n";
$tables2 = $db->select("SHOW TABLES LIKE '%tax%'");
foreach($tables2 as $t) { echo array_values((array)$t)[0] . "\n"; }
$tables3 = $db->select("SHOW TABLES LIKE '%wht%'");
foreach($tables3 as $t) { echo array_values((array)$t)[0] . "\n"; }
$tables4 = $db->select("SHOW TABLES LIKE '%vat%'");
foreach($tables4 as $t) { echo array_values((array)$t)[0] . "\n"; }

// Payment sources full
echo "\n=== PAYMENT_SOURCES COLUMNS ===\n";
$psColumns = $db->select('SHOW COLUMNS FROM payment_sources');
foreach($psColumns as $c) { echo $c->Field . " " . $c->Type . "\n"; }

// COS-002 parent_id context  
echo "\n=== COS-002 PARENT CONTEXT ===\n";
$cos2 = $db->table('chart_of_accounts')->where('code','COS-002')->first();
if($cos2) {
    echo "COS-002: id={$cos2->id} name={$cos2->name} parent_id={$cos2->parent_id} postable={$cos2->is_postable} active={$cos2->is_active}\n";
    // Can COS-021/022/023 be children of COS-002?
    echo "COS-002 is_postable={$cos2->is_postable} (must be 0 for children to be postable children)\n";
} else {
    echo "COS-002: NOT IN db_test\n";
    // Does it exist in live db?
    echo "Checking parent_id of COS-001, COS-003 for clues:\n";
    foreach(['COS-001','COS-003','COS-008','COS-016','COS-018','COS-020'] as $c) {
        $a = $db->table('chart_of_accounts')->where('code',$c)->first();
        if($a) { echo "$c: parent_id={$a->parent_id}\n"; }
    }
}

// Also check the MPESA source payment_receipt_capable field
echo "\n=== MPESA SOURCE FIELDS ===\n";
$mpesa = $db->table('payment_sources')->where('code','MPESA')->first();
echo json_encode($mpesa) . "\n";

// CARD source
echo "\n=== CARD SOURCE FIELDS ===\n";
$card = $db->table('payment_sources')->where('code','CARD')->first();
echo json_encode($card) . "\n";
