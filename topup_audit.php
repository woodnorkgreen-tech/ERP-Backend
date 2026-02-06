<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;

echo "ID | AMOUNT | PREV_BAL | CREATED_AT\n";
echo "---|--------|----------|-----------\n";
$all = PettyCashTopUp::orderBy('id')->get();
foreach ($all as $t) {
    printf("%-2d | %8.2f | %8.2f | %s\n", $t->id, $t->amount, $t->previous_balance, $t->created_at);
}
