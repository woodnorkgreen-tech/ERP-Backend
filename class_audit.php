<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- CLASSIFICATION SUMS ---\n\n";

$classes = DB::table('petty_cash_disbursements')
    ->select('classification', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
    ->groupBy('classification')
    ->get();

foreach ($classes as $c) {
    echo "Class: " . str_pad($c->classification ?? 'NULL', 15) . " | Count: " . str_pad($c->count, 5) . " | Total: " . number_format($c->total, 2) . "\n";
}
