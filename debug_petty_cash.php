<?php

use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$repo = app(PettyCashRepository::class);
$res = $repo->getFlatTransactions([], 100); // Get more to be sure

echo "TYPE | AMOUNT | STATUS | ARCHIVED\n";
echo str_repeat("-", 40) . "\n";
foreach ($res as $t) {
    printf("%-12s | %10.2f | %-8s | %d\n", $t->type, $t->amount, $t->status, $t->is_archived);
}
echo str_repeat("-", 40) . "\n";
echo "Total Count: " . $res->total() . "\n";
echo "Total Sum (all in list): " . $res->sum('amount') . "\n";
echo "Total Sum (disbursements only): " . $res->where('type', 'disbursement')->sum('amount') . "\n";
echo "Total Sum (top-ups only): " . $res->where('type', 'top_up')->sum('amount') . "\n";
