<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking all element_materials:\n";

$materials = DB::table('element_materials')->get();

foreach($materials as $material) {
    echo "ID: {$material->id}, Description: {$material->description}, is_additional: {$material->is_additional}\n";
    echo "  Virtual ID would be: materials_additional_{$material->id}\n";
}
