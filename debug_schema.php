<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Budget Additions Table Schema:\n";
$columns = Schema::getColumnListing('budget_additions');
print_r($columns);

echo "\nForeign Keys:\n";
try {
    $foreignKeys = DB::select('
        SELECT
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_NAME = "budget_additions"
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ');
    foreach($foreignKeys as $fk) {
        echo $fk->CONSTRAINT_NAME . ': ' . $fk->COLUMN_NAME . ' -> ' . $fk->REFERENCED_TABLE_NAME . '.' . $fk->REFERENCED_COLUMN_NAME . "\n";
    }
} catch (Exception $e) {
    echo "Error getting foreign keys: " . $e->getMessage() . "\n";
}

echo "\nTable Structure:\n";
try {
    $structure = DB::select('DESCRIBE budget_additions');
    foreach($structure as $col) {
        echo $col->Field . ' - ' . $col->Type . ' - ' . ($col->Null == 'YES' ? 'NULL' : 'NOT NULL') . ' - ' . ($col->Default ?? 'NO DEFAULT') . "\n";
    }
} catch (Exception $e) {
    echo "Error getting table structure: " . $e->getMessage() . "\n";
}
