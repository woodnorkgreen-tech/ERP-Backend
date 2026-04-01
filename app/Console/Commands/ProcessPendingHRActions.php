<?php

namespace App\Console\Commands;

use App\Modules\HR\Models\HRAction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPendingHRActions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hr:process-actions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending HR actions whose effective date has been reached';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today();
        
        $pendingActions = HRAction::where('status', 'pending_execution')
            ->whereDate('effective_date', '<=', $today)
            ->with(['employee', 'type'])
            ->get();

        if ($pendingActions->isEmpty()) {
            $this->info('No pending HR actions to process.');
            return;
        }

        $this->info("Found {$pendingActions->count()} pending actions. Processing...");

        foreach ($pendingActions as $action) {
            DB::transaction(function () use ($action) {
                try {
                    $employee = $action->employee;
                    
                    if (!$employee) {
                        $this->error("Action ID {$action->id}: Employee not found. Skipping.");
                        return;
                    }

                    // For WARNINGS, we typically don't update the employee record
                    if ($action->type && $action->type->code !== 'WARNING') {
                        $employee->update($action->new_data);
                    }

                    $action->update(['status' => 'executed']);
                    
                    $this->info("Successfully executed {$action->type->name} for {$employee->first_name} {$employee->last_name}");
                } catch (\Exception $e) {
                    $this->error("Failed to execute action ID {$action->id}: " . $e->getMessage());
                    Log::error("HR Action Execution Error (ID: {$action->id}): " . $e->getMessage());
                }
            });
        }

        $this->info('Finished processing HR actions.');
    }
}
