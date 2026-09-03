<?php

namespace App\Modules\ProcurementStores\Console;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Services\BoardValuationService;
use Illuminate\Console\Command;

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
 * This is the bulk operator's door onto the same act Stores performs from
 * POST /boards/record-valuation. Both go through BoardValuationService, so the
 * rules are identical: only a board still in Stores and still unpriced is
 * touched, an offcut takes its area share of the sheet price, and every change
 * writes a BoardMovement. A board that has already been issued is repriced from
 * the Stores finance exception instead — its posting is what needs correcting,
 * and repricing the board would not restate it.
 */
class ValueUnpricedBoardsCommand extends Command
{
    protected $signature = 'boards:value-unpriced
        {--dry-run : Report what would change and write nothing}
        {--material= : Restrict to one library material id}
        {--value= : Use this unit value instead of the material catalogue cost}';

    protected $description = 'Give a value to boards that were registered unpriced, so they can be issued';

    public function handle(BoardValuationService $valuation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $override = $this->option('value') !== null ? (float) $this->option('value') : null;

        if ($override !== null && $override <= 0) {
            $this->error('--value must be greater than zero.');

            return self::FAILURE;
        }

        $boards = $valuation->unvaluedQuery()
            ->when($this->option('material'), fn ($q, $id) => $q->where('library_material_id', $id))
            ->orderBy('library_material_id')
            ->orderBy('id')
            ->get();

        if ($boards->isEmpty()) {
            $this->info('Every issuable board carries a value.');

            return self::SUCCESS;
        }

        // Weighted average first, then the material's default price. Both are
        // catalogue figures, but the average is built from real receipts.
        $catalogue = LibraryMaterial::whereIn('id', $boards->pluck('library_material_id')->unique())
            ->get(['id', 'unit_cost', 'default_unit_cost']);
        $costs = $catalogue->mapWithKeys(fn ($material) => [
            $material->id => (float) $material->unit_cost > 0
                ? (float) $material->unit_cost
                : (float) ($material->default_unit_cost ?? 0),
        ]);

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

            $result = $valuation->record($group, $value, null, 'Applied by boards:value-unpriced.');
            $priced += $result['priced']->count();

            if ($result['catalogue_updated']) {
                $this->line("    {$name} had no catalogue cost — seeded from this figure.");
            }
        }

        if ($unpriceable) {
            $this->newLine();
            $this->warn('These materials have neither a catalogue cost nor a default price, so their boards cannot be priced automatically:');
            $this->table(['Material id', 'Material', 'Unpriced boards', 'Tracking codes'], $unpriceable);
            $this->line('Set a default price on the material in the Material Library, or supply the receipt price per board:');
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
