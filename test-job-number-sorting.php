<?php

// Quick test script to verify job number sorting
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Job Number Sorting Logic\n";
echo str_repeat("=", 50) . "\n\n";

// Get actual job numbers from database
$jobNumbers = DB::table('project_enquiries')
    ->whereNotNull('job_number')
    ->orderByRaw("
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, '-', 3), '-', -1) AS UNSIGNED) DESC,
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, '-', 2), '-', -1) AS UNSIGNED) DESC,
        CAST(SUBSTRING_INDEX(job_number, '-', -1) AS UNSIGNED) DESC
    ")
    ->limit(10)
    ->pluck('job_number');

echo "Current Job Numbers (DESC - Latest First):\n";
foreach ($jobNumbers as $index => $jobNumber) {
    echo sprintf("  %d. %s\n", $index + 1, $jobNumber);
}

echo "\n";
echo "Total approved projects: " . DB::table('project_enquiries')->whereNotNull('job_number')->count() . "\n";

echo "\n✅ Expected Order for Jan 2026:\n";
echo "  WNG-01-2026-004 (Latest)\n";
echo "  WNG-01-2026-003\n";
echo "  WNG-01-2026-002\n";
echo "  WNG-01-2026-001 (First)\n";
