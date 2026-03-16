<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$columns = DB::select('DESCRIBE projects');
foreach ($columns as $column) {
    echo $column->Field . " - " . $column->Type . "\n";
}
