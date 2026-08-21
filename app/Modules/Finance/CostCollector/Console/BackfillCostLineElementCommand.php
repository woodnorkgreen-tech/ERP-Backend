<?php

namespace App\Modules\Finance\CostCollector\Console;

use App\Models\ElementMaterial;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills `details.element` onto material cost lines posted before the element
 * was carried as a fact.
 *
 * The element was always present in the materials list and the budget, but the
 * projector folded it into the description text and every producer downstream
 * inherited only the category. So the cost account could total materials but not
 * say which part of the job the materials were for — the one question a project
 * manager actually asks of it.
 *
 * Resolution is by identity, never by parsing the description back apart:
 *
 *   1. `details.project_material_id` — an `element_materials` row, held either as
 *      its integer id (what Stores movements carry) or its `persistent_id` UUID
 *      (what budget JSON carries). Both are tried.
 *   2. Failing that, the planned line this spend consumes, which may already have
 *      been resolved by rule 1.
 *
 * A line that matches neither is left alone and reported. An unresolved line is
 * a real gap worth seeing, not something to paper over with a guess.
 */
class BackfillCostLineElementCommand extends Command
{
    protected $signature = 'finance:backfill-cost-line-elements
        {--dry-run : Report what would change and write nothing}
        {--enquiry= : Restrict to one project enquiry id}';

    protected $description = 'Backfill the project element and material name onto material cost lines that predate them being recorded';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing will be written.' : 'Applying backfill.');

        $lines = CostLine::query()
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category')) = 'materials'")
            ->where(function ($q) {
                $q->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(details, '$.element')), '') = ''")
                    ->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(details, '$.material')), '') = ''");
            })
            ->when($this->option('enquiry'), fn ($q, $id) => $q->where('project_enquiry_id', $id))
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            $this->info('Every material cost line already names its element.');

            return self::SUCCESS;
        }

        $resolved = 0;
        $unresolved = [];

        foreach ($lines as $line) {
            $repair = array_filter([
                'element' => filled($line->details['element'] ?? null) ? null : $this->elementFor($line),
                'material' => filled($line->details['material'] ?? null) ? null : $this->materialFor($line),
            ], fn ($value) => $value !== null);

            if ($repair === []) {
                $unresolved[] = $line;
                continue;
            }

            if (! $dryRun) {
                DB::transaction(function () use ($line, $repair) {
                    // Written with a fresh read inside the transaction so a
                    // concurrent producer's details are not clobbered wholesale.
                    $current = CostLine::query()->lockForUpdate()->find($line->id);

                    if (! $current) {
                        return;
                    }

                    $current->forceFill([
                        'details' => [...($current->details ?? []), ...$repair],
                    ])->save();
                });
            }

            $resolved++;
        }

        $this->newLine();
        $this->line("Material lines missing an element or a material name: {$lines->count()}");
        $this->info(($dryRun ? 'Resolvable' : 'Resolved') . ": {$resolved}");

        if ($unresolved !== []) {
            $this->warn('Unresolved: ' . count($unresolved));
            $this->table(
                ['Ref', 'Enquiry', 'Nature', 'Amount', 'Description'],
                collect($unresolved)->take(25)->map(fn (CostLine $line) => [
                    $line->ref,
                    $line->project_enquiry_id,
                    $line->nature,
                    $line->net_amount,
                    str($line->description)->limit(48),
                ])->all(),
            );
            $this->line('These carry no project material line and no budget link, so no element can be');
            $this->line('established without guessing. They group under "Unassigned".');
        }

        return self::SUCCESS;
    }

    /**
     * The material a cost line is for, by identity: the catalogue item it names,
     * or the project material line it was raised against. Never the description,
     * which is a sentence built for a human and carries a movement verb and a
     * quantity as often as it carries a name.
     */
    private function materialFor(CostLine $line): ?string
    {
        $libraryId = $line->details['library_material_id'] ?? null;

        if (filled($libraryId)) {
            $name = LibraryMaterial::find((int) $libraryId)?->material_name;

            if (filled($name)) {
                return (string) $name;
            }
        }

        $reference = $line->details['project_material_id'] ?? null;

        if (filled($reference)) {
            $material = ctype_digit((string) $reference)
                ? ElementMaterial::find((int) $reference)
                : ElementMaterial::where('persistent_id', (string) $reference)->first();

            if (filled($material?->description)) {
                return (string) $material->description;
            }
        }

        return null;
    }

    private function elementFor(CostLine $line): ?string
    {
        $reference = $line->details['project_material_id'] ?? null;

        if (filled($reference)) {
            $material = ctype_digit((string) $reference)
                ? ElementMaterial::with('element:id,name')->find((int) $reference)
                : ElementMaterial::with('element:id,name')->where('persistent_id', (string) $reference)->first();

            $name = $material?->element?->name;

            if (filled($name)) {
                return (string) $name;
            }
        }

        // Spend that claimed a budget line inherits that line's element, which
        // rule 1 may already have supplied on this same pass.
        if ($line->consumes_line_id) {
            $planned = CostLine::find($line->consumes_line_id);
            $name = $planned?->details['element'] ?? null;

            if (filled($name)) {
                return (string) $name;
            }
        }

        return null;
    }
}
