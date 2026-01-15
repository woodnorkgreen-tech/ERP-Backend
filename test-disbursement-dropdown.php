<?php

// Test the approved-wng endpoint used by Disbursement Form
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Disbursement Form Dropdown API\n";
echo str_repeat("=", 50) . "\n\n";

// Simulate the actual API call
$projects = DB::table('project_enquiries')
    ->where('quote_approved', true)
    ->whereNotNull('job_number')
    ->select('id', 'job_number', 'project_id', 'title')
    // Sort by Year DESC, Month DESC, Sequential Number DESC
    // Format: WNG-MM-YYYY-NNN
    ->orderByRaw("
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, '-', 3), '-', -1) AS UNSIGNED) DESC,
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, '-', 2), '-', -1) AS UNSIGNED) DESC,
        CAST(SUBSTRING_INDEX(job_number, '-', -1) AS UNSIGNED) DESC
    ")
    ->take(100)
    ->get();

echo "Endpoint: GET /api/projects/approved-wng\n";
echo "Total Approved Projects: " . $projects->count() . "\n\n";

echo "Dropdown Order (Latest First):\n";
echo str_repeat("-", 50) . "\n";

foreach ($projects as $index => $project) {
    echo sprintf(
        "%2d. %s | %s\n",
        $index + 1,
        $project->job_number ?? 'N/A',
        substr($project->title ?? 'Untitled', 0, 40)
    );
}

echo "\n✅ Expected Behavior:\n";
echo "   - WNG-01-2026-009 should be FIRST (newest)\n";
echo "   - WNG-01-2026-001 should be LAST (oldest)\n";
echo "   - Sorted numerically, not alphabetically\n";
