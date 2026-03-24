<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$enquiries = App\Models\ProjectEnquiry::where('estimated_budget', '>', 0)->take(5)->get();
foreach ($enquiries as $e) {
    echo "E_ID: {$e->id} | Estimated_Budget: {$e->estimated_budget}\n";
}
