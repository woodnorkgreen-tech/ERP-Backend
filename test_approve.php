<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$action = App\Modules\HR\Models\HRAction::where('action_type', 'PROFILE_UPDATE')->latest('id')->first();
if ($action) {
    // Inject some fake new_data just to test
    $action->new_data = ['first_name' => 'NEWNAME_TEST'];
    $action->status = 'pending_approval';
    $action->save();

    // Now call the logic from approveAction
    if ($action->type && $action->type->code === 'PROFILE_UPDATE') {
        $action->employee->update($action->new_data);
    }
    
    echo "Employee name after update: " . $action->employee->fresh()->first_name . "\n";
} else {
    echo "No action found.\n";
}
