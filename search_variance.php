<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['petty_cash_top_ups', 'petty_cash_disbursements', 'petty_cash_balances'];
$searchValue = 2530;

echo "--- Searching for $searchValue in Petro Cash Tables ---\n\n";

foreach ($tables as $table) {
    echo "Checking table: $table\n";
    $columns = DB::getSchemaBuilder()->getColumnListing($table);
    
    $query = DB::table($table);
    $first = true;
    foreach ($columns as $column) {
        if ($first) {
            $query->where($column, 'like', "%$searchValue%");
            $first = false;
        } else {
            $query->orWhere($column, 'like', "%$searchValue%");
        }
    }
    
    $results = $query->get();
    if ($results->isEmpty()) {
        echo "No matches in $table\n";
    } else {
        echo "Found " . $results->count() . " matches in $table:\n";
        foreach ($results as $row) {
            print_r((array)$row);
        }
    }
    echo str_repeat("-", 40) . "\n";
}

echo "\n--- Search Finished ---\n";
