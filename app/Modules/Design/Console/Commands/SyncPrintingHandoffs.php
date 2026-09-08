<?php

namespace App\Modules\Design\Console\Commands;

use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Printing\Services\PrintIntakeService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SyncPrintingHandoffs extends Command
{
    protected $signature = 'design:sync-printing-handoffs {--dry-run : Show what would be synced without writing changes}';

    protected $description = 'Create missing pending Printing handoffs for graphic design items already marked print-ready or handed-off.';

    public function handle(DesignHandoffService $handoffs, PrintIntakeService $printingIntake): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        $items = DesignItem::query()
            ->where('stream', DesignItem::STREAM_GRAPHIC)
            ->whereIn('status', ['print_ready', 'handed_off'])
            ->whereDoesntHave('handoffs', fn ($query) => $query->where('target_module', 'printing'))
            ->with(['job.enquiry.client', 'type', 'printMaterial', 'documents'])
            ->orderBy('id')
            ->get();

        $this->info("Found {$items->count()} graphic item(s) without Printing handoffs.");

        foreach ($items as $item) {
            try {
                if ($dryRun) {
                    $this->line("[dry-run] Would sync item #{$item->id}: {$item->title}");
                    $created++;
                    continue;
                }

                $handoff = $handoffs->createPrintingHandoffOnce($item);
                $printingIntake->accept($handoff);

                $this->line("Synced item #{$item->id}: {$item->title}");
                $created++;
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();
                $this->warn("Skipped item #{$item->id}: {$message}");
                $skipped++;
            }
        }

        $pending = DesignHandoff::query()
            ->where('target_module', 'printing')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        $this->info("Found {$pending->count()} pending Printing handoff(s) to queue.");

        foreach ($pending as $handoff) {
            if ($dryRun) {
                $this->line("[dry-run] Would queue handoff #{$handoff->id} for item #{$handoff->design_item_id}");
                $created++;
                continue;
            }

            $printingIntake->accept($handoff);
            $this->line("Queued handoff #{$handoff->id} for item #{$handoff->design_item_id}");
            $created++;
        }

        $this->info(($dryRun ? 'Would sync' : 'Synced') . " {$created}; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
