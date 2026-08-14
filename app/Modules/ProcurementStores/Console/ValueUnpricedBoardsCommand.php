<?php

namespace App\Modules\ProcurementStores\Console;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prices boards that were registered without a value.
 *
 * Board check-in used to value each board from the catalogue `unit_cost` read
 * off a model instance loaded *before* check-in recalculated it. On a material's
 * first delivery that figure is still zero, so every board in the batch landed
 * unpriced — and `fulfil()` refuses to issue an unpriced board, because doing so
 * would post a zero-value cost to the project.
 *
 * Registration now takes the receipt price directly. This repairs the boards
 * already recorded, using the material's current catalogue cost, which after at
 * least one priced receipt holds the weighted average.
 *
 * Every change writes a BoardMovement so the valuation has an audit trail.
 * Boards that are Consumed or Scrapped are left alone: their cost is already
 * history and repricing them would restate closed project cost.
 */
class ValueUnpricedBoardsCommand extends Command
{
    protected $signature = 'boards:value-unpriced
        {--dry-run : Report what would change and write nothing}
        {--material= : Restrict to one library material id}
        {--value= : Use this unit value instead of the material catalogue cost}';

    protected $description = 'Give a value to boards that were registered unpriced, so they can be issued';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $override = $this->option('value') !== null ? (float) $this->option('value') : null;

        if ($override !== null && $override <= 0) {
            $this->error('--value must be greater than zero.');

            return self::FAILURE;
        }

        $boards = Board::query()
            ->where(fn ($q) => $q->whereNull('current_value')->orWhere('current_value', '<=', 0))
            ->whereNotIn('status', ['Consumed', 'Scrapped'])
            ->when($this->option('material'), fn ($q, $id) => $q->where('library_material_id', $id))
            ->orderBy('library_material_id')
            ->orderBy('id')
            ->get();

        if ($boards->isEmpty()) {
            $this->info('Every issuable board carries a value.');

            return self::SUCCESS;
        }

        $costs = LibraryMaterial::whereIn('id', $boards->pluck('library_material_id')->unique())
            ->pluck('unit_cost', 'id');

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing will be written.' : 'Applying board valuations.');
        $this->newLine();

        $priced = 0;
        $unpriceable = [];

        foreach ($boards->groupBy('library_material_id') as $materialId => $group) {
            $value = $override ?? (float) ($costs[$materialId] ?? 0);
            $name = LibraryMaterial::whereKey($materialId)->value('material_name') ?? "Material #{$materialId}";

            if ($value <= 0) {
                $unpriceable[] = [
                    $materialId,
                    $name,
                    $group->count(),
                    $group->pluck('tracking_code')->take(4)->implode(', ')
                        . ($group->count() > 4 ? ' …' : ''),
                ];
                continue;
            }

            $this->line("  {$name} — {$group->count()} board(s) at " . number_format($value, 2));

            if ($dryRun) {
                $priced += $group->count();
                continue;
            }

            DB::transaction(function () use ($group, $value, &$priced) {
                foreach ($group as $board) {
                    $locked = Board::whereKey($board->id)->lockForUpdate()->first();
                    if (! $locked || (float) $locked->current_value > 0) {
                        continue;
                    }

                    $locked->update(['current_value' => $value]);
                    BoardMovement::create([
                        'board_id' => $locked->id,
                        'from_status' => $locked->status,
                        'to_status' => $locked->status,
                        'performed_by' => null,
                        'notes' => 'Receipt valuation recorded by boards:value-unpriced — ' . number_format($value, 2),
                        'job_ref' => $locked->assigned_job_ref,
                    ]);
                    $priced++;
                }
            });
        }

        if ($unpriceable) {
            $this->newLine();
            $this->warn('These materials have no catalogue cost either, so their boards cannot be priced automatically:');
            $this->table(['Material id', 'Material', 'Unpriced boards', 'Tracking codes'], $unpriceable);
            $this->line('Supply the receipt price per board:');
            foreach ($unpriceable as [$id, $name]) {
                $this->line("  php artisan boards:value-unpriced --material={$id} --value=<price>   # {$name}");
            }
        }

        $this->newLine();
        $this->line($dryRun
            ? "{$priced} board(s) would be priced. No changes written."
            : "{$priced} board(s) priced.");

        return self::SUCCESS;
    }
}
