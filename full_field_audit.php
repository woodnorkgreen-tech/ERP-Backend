<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$allActive = PettyCashDisbursement::active()->notArchived()->orderBy('id')->get();
$output = "ID | AMOUNT | CLASS | PROJECT | METHOD | DATE_D | CREATED_AT\n";
$output .= str_repeat("-", 100) . "\n";
foreach ($allActive as $d) {
    $output .= sprintf("%-4d | %8.2f | %-12s | %-20s | %-8s | %-10s | %s\n", 
        $d->id, $d->amount, $d->classification, 
        substr($d->project_name ?? 'N/A', 0, 20),
        $d->payment_method,
        $d->date_disbursed,
        $d->created_at);
}
file_put_contents('audit_results.txt', $output);
echo "Results written to audit_results.txt\n";
