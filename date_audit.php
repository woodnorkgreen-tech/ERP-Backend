<?php

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$allActive = PettyCashDisbursement::active()->notArchived()->orderBy('id')->get();
echo "Total Active Records: " . $allActive->count() . "\n";
echo "ID | AMOUNT | DATE_DISBURSED | CREATED_AT\n";
echo "---|--------|----------------|-----------\n";
foreach ($allActive as $d) {
    echo "{$d->id} | {$d->amount} | {$d->date_disbursed} | {$d->created_at}\n";
}
