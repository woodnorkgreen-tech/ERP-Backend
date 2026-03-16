<?php

use App\Models\ProjectEnquiry;
use App\Models\Project;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$recentEnquiries = ProjectEnquiry::orderBy('updated_at', 'desc')->take(5)->get();

echo "RECENT ENQUIRIES:\n";
foreach ($recentEnquiries as $e) {
    echo "ID: {$e->id} | Title: {$e->title} | Status: {$e->status} | Job #: {$e->job_number} | Quote Approved: " . ($e->quote_approved ? 'YES' : 'NO') . " | Updated: {$e->updated_at}\n";
    
    $project = Project::where('enquiry_id', $e->id)->first();
    if ($project) {
        echo "  -> Linked Project: {$project->project_id} | Status: {$project->status}\n";
    } else {
        echo "  -> No linked Project record found.\n";
    }
}
