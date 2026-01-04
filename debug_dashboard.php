<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Services\ProjectsDashboardService;

$service = new ProjectsDashboardService();

echo "--- ENQUIRY METRICS ---\n";
print_r($service->getEnquiryMetrics());

echo "\n--- FINANCIAL METRICS ---\n";
print_r($service->getFinancialMetrics());

echo "\n--- COMMAND CENTER ---\n";
print_r($service->getCommandCenterData());

echo "\n--- PROJECTS DATA ---\n";
$projects = ProjectEnquiry::all(['id', 'title', 'status', 'estimated_budget', 'budget']);
foreach ($projects as $p) {
    echo "ID: {$p->id}, Status: {$p->status}, Est: {$p->estimated_budget}, Budget: {$p->budget}\n";
}
