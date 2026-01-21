<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\ProductionService;
use Illuminate\Support\Facades\Log;

class CreateWorkOrdersForExistingProjects extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productionService = app(ProductionService::class);
        
        echo "Creating work orders for existing projects...\n";
        
        $results = $productionService->createWorkOrdersForExistingProjects();
        
        echo "Results:\n";
        echo "- Created: {$results['created']} work orders\n";
        echo "- Skipped: {$results['skipped']} projects (already had work orders)\n";
        
        if (!empty($results['errors'])) {
            echo "- Errors: " . count($results['errors']) . "\n";
            foreach ($results['errors'] as $error) {
                echo "  - $error\n";
            }
        }
        
        echo "Work order creation process completed!\n";
    }
}
