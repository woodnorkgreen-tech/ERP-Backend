<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Modules\Teams\Models\TeamsTask;
use App\Modules\Teams\Models\TeamsMember;

$members = TeamsMember::with(['teamsTask.task', 'teamsTask.category'])->get();
foreach ($members as $m) {
    echo "Member: {$m->member_name}\n";
    echo "  Task ID: " . ($m->teamsTask->id ?? 'N/A') . "\n";
    echo "  Category: " . ($m->teamsTask->category->name ?? 'N/A') . "\n";
    echo "  Project Enquiry ID (from task): " . ($m->teamsTask->task->project_enquiry_id ?? 'N/A') . "\n";
    echo "  Project ID (direct): " . ($m->teamsTask->project_id ?? 'N/A') . "\n";
    echo "-------------------\n";
}
