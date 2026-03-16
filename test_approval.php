<?php

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Actions\ApproveQuoteAction;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$enquiryId = 3; // STARBASE - which is not approved yet
$enquiry = ProjectEnquiry::find($enquiryId);

if (!$enquiry) {
    die("Enquiry not found\n");
}

$user = User::first(); // Assuming first user is admin
if (!$user) {
    die("User not found\n");
}

echo "Attempting to approve quote for Enquiry ID: {$enquiryId}...\n";

try {
    $action = $app->make(ApproveQuoteAction::class);
    $action->execute($enquiry, $user->id);
    echo "SUCCESS: Quote approved!\n";
    
    $enquiry->refresh();
    echo "New Job Number: " . ($enquiry->job_number ?: 'NONE') . "\n";
    echo "Status: " . $enquiry->status . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
