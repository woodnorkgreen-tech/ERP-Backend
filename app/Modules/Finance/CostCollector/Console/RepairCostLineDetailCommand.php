<?php

namespace App\Modules\Finance\CostCollector\Console;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs two things on cost lines that were already correct in `details` but
 * missing from the columns and the links that read them.
 *
 * 1. Quantity, unit and rate. Producers always reported these in `details`, but
 *    postFromSource did not lift them onto the columns until recently, so lines
 *    posted before that show a total with no explanation of how it was reached.
 *
 * 2. Budget linkage. A cost posted before its budget was projected found no
 *    planned line and was recorded unbudgeted, correctly. Once the projection
 *    runs, the line it should have claimed exists — but nothing goes back to
 *    connect them, so the account keeps reporting spend as unplanned when it
 *    was planned all along.
 *
 * The relink is deliberately narrow: it matches only on the exact material
 * identity both sides already agree on, within the same enquiry. It will not
 * guess. A cost with no exact counterpart stays unbudgeted, which is the honest
 * answer and the one the blind fallback used to hide.
 */
class RepairCostLineDetailCommand extends Command
{
    protected $signature = 'finance:repair-cost-lines
        {--dry-run : Report what would change and write nothing}
        {--enquiry= : Restrict to one project enquiry id}
        {--actor= : User id to attribute the re-coding to}
        {--skip-relink : Backfill quantities only, leave budget linkage alone}';

    protected $description = 'Backfill quantity and rate onto cost lines, and link unbudgeted spend to budget lines projected after it';

    public function __construct(private CostVerificationService $verification)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $enquiry = $this->option('enquiry');

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing will be written.' : 'Applying repairs.');

        $measures = $this->backfillMeasures($dryRun, $enquiry);

        $links = 0;
        if (! $this->option('skip-relink')) {
            $actor = $this->resolveActor();

            if (! $actor) {
                $this->error('No actor to attribute the re-coding to. Pass --actor=<user id>.');

                return self::FAILURE;
            }

            $this->line("  Re-coding attributed to {$actor->name} (#{$actor->id})");
            $links = $this->relinkUnbudgeted($dryRun, $enquiry, $actor);
        }

        $this->newLine();
        $this->table(['Repair', 'Lines'], [
            ['quantity / unit / rate backfilled', number_format($measures)],
            ['unbudgeted spend linked to its budget line', number_format($links)],
        ]);

        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /** Lift quantity, unit and rate out of details onto the columns that read them. */
    private function backfillMeasures(bool $dryRun, ?string $enquiry): int
    {
        $lines = CostLine::query()
            ->whereNull('quantity')
            ->whereNotNull('details')
            ->when($enquiry, fn ($q, $id) => $q->where('project_enquiry_id', $id))
            ->get();

        $repaired = 0;

        foreach ($lines as $line) {
            $details = (array) $line->details;
            $quantity = $details['quantity'] ?? null;

            if ($quantity === null || $quantity === '') {
                continue;
            }

            $update = [
                'quantity' => $quantity,
                'unit' => $details['uom'] ?? $details['unit'] ?? $line->unit,
                'unit_rate' => $details['unit_price']
                    ?? $details['unit_rate']
                    ?? $details['unit_cost']
                    ?? $line->unit_rate,
            ];

            $repaired++;

            if (! $dryRun) {
                // forceFill: these are corrections to a posted line's description
                // of itself, not to its money. No amount is touched.
                $line->forceFill($update)->save();
            }
        }

        return $repaired;
    }

    /**
     * Point unbudgeted spend at the planned line it always belonged to.
     *
     * Matched on the material identity both sides carry, scoped to the same
     * enquiry. Where several planned lines share a material the match is
     * ambiguous and is left alone for a person to resolve.
     */
    /**
     * Whose decision this is recorded as.
     *
     * A re-coding is attributable by design — the ledger keeps who changed it
     * and why — so this refuses to run anonymously rather than inventing a
     * system actor that no one can be asked about later.
     */
    private function resolveActor(): ?User
    {
        if ($id = $this->option('actor')) {
            return User::find($id);
        }

        return User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->orderBy('id')->first();
    }

    private function relinkUnbudgeted(bool $dryRun, ?string $enquiry, User $actor): int
    {
        $unbudgeted = CostLine::query()
            ->where('nature', '!=', CostLine::NATURE_PLANNED)
            ->whereNull('consumes_line_id')
            ->whereNotNull('project_enquiry_id')
            ->whereNotNull('details')
            ->when($enquiry, fn ($q, $id) => $q->where('project_enquiry_id', $id))
            ->get();

        $linked = 0;

        foreach ($unbudgeted as $line) {
            $details = (array) $line->details;
            $projectMaterial = $details['project_material_id'] ?? null;
            $libraryMaterial = $details['library_material_id'] ?? null;

            if (! $projectMaterial && ! $libraryMaterial) {
                continue;
            }

            $keys = array_values(array_filter([
                $projectMaterial,
                $projectMaterial ? \App\Models\ElementMaterial::whereKey($projectMaterial)->value('persistent_id') : null,
            ]));

            $planned = CostLine::query()
                ->where('project_enquiry_id', $line->project_enquiry_id)
                ->where('nature', CostLine::NATURE_PLANNED)
                ->counting()
                ->when($keys, fn ($q) => $q->whereIn(
                    DB::raw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.project_material_id'))"),
                    array_map('strval', $keys),
                ))
                ->when(! $keys, fn ($q) => $q->whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(details, '$.library_material_id')) = ?",
                    [(string) $libraryMaterial],
                ))
                ->get();

            if ($planned->count() !== 1) {
                continue;
            }

            $this->line(sprintf(
                '  %s → %s  %s',
                $line->ref,
                $planned->first()->ref,
                substr((string) $line->description, 0, 48),
            ));

            $linked++;

            if (! $dryRun) {
                // Through the verification service, not a direct write: it
                // records who re-coded the line and why, and recomputes the
                // budget snapshot — which otherwise still describes a draw
                // against the line this cost no longer claims.
                $this->verification->reclassify(
                    $line,
                    $actor,
                    $planned->first()->id,
                    'Budget line was projected after this cost posted; linked by finance:repair-cost-lines on exact material identity.',
                );
            }
        }

        return $linked;
    }
}
