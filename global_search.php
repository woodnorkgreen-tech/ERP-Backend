<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$searchValue = 2530;
$tables = DB::select('SHOW TABLES');
$databaseName = DB::getDatabaseName();
$tableKey = "Tables_in_{$databaseName}";

echo "--- GLOBAL SEARCH FOR $searchValue ---\n\n";

foreach ($tables as $tableObj) {
    $table = $tableObj->$tableKey;
    
    // Skip some large or irrelevant tables if needed, but for now check all
    if (in_array($table, ['migrations', 'failed_jobs', 'personal_access_tokens'])) continue;

    $columns = DB::getSchemaBuilder()->getColumnListing($table);
    $query = DB::table($table);
    $hasNumericColumns = false;
    
    $query->where(function($q) use ($columns, $searchValue) {
        foreach ($columns as $column) {
            $q->orWhere($column, '=', $searchValue)
              ->orWhere($column, 'like', "%$searchValue%");
        }
    });

    try {
        $count = $query->count();
        if ($count > 0) {
            echo "MATCH FOUND in table: $table ($count rows)\n";
            $results = $query->limit(5)->get();
            foreach ($results as $row) {
                print_r((array)$row);
            }
            echo str_repeat("-", 40) . "\n";
        }
    } catch (\Exception $e) {
        // Skip tables with structure issues or incompatible columns
    }
}

echo "\n--- GLOBAL SEARCH FINISHED ---\n";
