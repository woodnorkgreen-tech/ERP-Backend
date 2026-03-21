<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$table = 'notifications';
if (Schema::hasTable($table)) {
    $columns = Schema::getColumnListing($table);
    echo "Table '$table' columns:\n";
    print_r($columns);
} else {
    echo "Table '$table' does not exist.\n";
}
