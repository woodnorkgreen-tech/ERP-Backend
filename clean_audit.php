<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$allActive = PettyCashDisbursement::active()->notArchived()->orderBy('id')->get();
$total = 0;
echo "ID | AMOUNT\n";
echo "---|-------\n";
foreach ($allActive as $d) {
    printf("%-4d | %8.2f\n", $d->id, $d->amount);
    $total += $d->amount;
}
echo "---|-------\n";
echo "SUM: " . number_format($total, 2) . "\n";
echo "COUNT: " . $allActive->count() . "\n";
