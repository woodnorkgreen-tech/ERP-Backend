<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking if material ID 4 exists in element_materials table:\n";

$material = DB::table('element_materials')->where('id', 4)->first();

if ($material) {
    echo "Material found:\n";
    print_r($material);
} else {
    echo "Material with ID 4 not found!\n";
}

echo "\nChecking all materials in element_materials:\n";
$materials = DB::table('element_materials')->limit(10)->get();
foreach($materials as $mat) {
    echo "ID: {$mat->id}, Description: {$mat->description}\n";
}
