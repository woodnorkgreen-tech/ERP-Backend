<?php

namespace App\Console\Commands;

use App\Services\ProductionService;
use Illuminate\Console\Command;

class CreateWorkOrdersForProjects extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'production:create-work-orders-for-projects';

    /**
     * The console command description.
     */
    protected $description = 'Create work orders for existing projects that don\'t have them';

    /**
     * Execute the console command.
     */
    public function handle(\App\Modules\Production\Services\ProductionTaskAlignmentService $alignmentService): int
    {
        $this->info('Creating work orders for existing projects using Alignment Engine...');

        $results = $alignmentService->createWorkOrdersForExistingProjects();

        $this->info(" Results:");
        $this->info("   - Created: {$results['created']} work orders");
        $this->info("   - Skipped: {$results['skipped']} projects (already had work orders)");

        if (!empty($results['errors'])) {
            $this->error(" Errors:");
            foreach ($results['errors'] as $error) {
                $this->error("   - $error");
            }
            return 1;
        }

        $this->info(' Work order creation process completed successfully!');
        return 0;
    }
}
