<?php

use Illuminate\Support\Facades\DB;

// Check project_scope data format
echo "Checking project_scope data format...\n\n";

$enquiries = DB::table('project_enquiries')
    ->select('id', 'title', 'project_scope')
    ->limit(5)
    ->get();

if ($enquiries->isEmpty()) {
    echo "No enquiries found in database.\n";
} else {
    foreach ($enquiries as $enquiry) {
        echo "ID: {$enquiry->id}\n";
        echo "Title: {$enquiry->title}\n";
        echo "Project Scope (raw): ";
        var_dump($enquiry->project_scope);
        echo "Is JSON valid: " . (json_decode($enquiry->project_scope) !== null ? 'Yes' : 'No') . "\n";
        echo "---\n\n";
    }
}
