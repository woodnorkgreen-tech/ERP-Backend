<?php

use App\Modules\Finance\PettyCash\Controllers\PettyCashRequisitionController;
use Illuminate\Http\Request;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Request::capture()
);

// Manually test the controller method
try {
    $controller = app(PettyCashRequisitionController::class);
    $request = new Request(['query' => 'COS']);
    $response = $controller->searchPayees($request);
    echo "Success: " . $response->getContent();
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
