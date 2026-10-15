<?php

namespace App\Console\Commands;

use App\Modules\Projects\Actions\AutoCompleteOverdueProjectAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoCompleteOverdueProjects extends Command
{
    protected $signature = 'projects:auto-complete-overdue {--dry-run : List eligible projects without changing them}';

    protected $description = 'Complete active projects after three full overdue days, excluding projects on hold';

    public function handle(AutoCompleteOverdueProjectAction $action): int
    {
        $completed = 0;
        $failed = 0;

        $action->candidates()->select('project_enquiries.id')->chunkById(100, function ($enquiries) use ($action, &$completed, &$failed) {
            foreach ($enquiries as $enquiry) {
                if ($this->option('dry-run')) {
                    $this->line("Eligible project enquiry: {$enquiry->id}");

                    continue;
                }

                try {
                    $completed += (int) $action->execute($enquiry->id);
                } catch (\Throwable $exception) {
                    $failed++;
                    Log::error('Automatic overdue project completion failed.', [
                        'enquiry_id' => $enquiry->id,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->error("Could not complete project enquiry {$enquiry->id}.");
                }
            }
        });

        $this->info($this->option('dry-run') ? 'Dry run complete. No projects changed.' : "Completed {$completed} overdue project(s); {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
