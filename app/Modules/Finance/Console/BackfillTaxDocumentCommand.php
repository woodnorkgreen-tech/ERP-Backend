<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give existing cost lines the tax-point and PIN they were never asked for.
 *
 * `tax_point_date` is set at verification from now on, but every line verified
 * before that migration has none — and a null tax point drops a line out of
 * every claim schedule silently. The eTIMS gap report would show a clean sheet
 * not because there is nothing to claim but because it cannot see anything.
 *
 * Two fields are backfilled and one deliberately is not:
 *
 * - `tax_point_date` from `incurred_at`. For petty cash and manual capture the
 *   receipt date IS the incurred date, so this is right. For a Stores issue it
 *   is wrong — the material was bought months before it was consumed — and the
 *   command says so per line rather than pretending otherwise. The alternative
 *   is leaving the line invisible, which is worse: a wrong-but-close tax point
 *   surfaces on a schedule where a person can correct it.
 *
 * - `supplier_pin` from the supplier record, where the payee is one. This is a
 *   snapshot being taken late rather than at verification, which is a weaker
 *   claim than the real thing; it is still the PIN in force for a supplier
 *   whose record has not changed, which is nearly all of them.
 *
 * - `etims_invoice_no` is NEVER inferred. There is nothing in the data it could
 *   be derived from, and a fabricated control number on a VAT return is fraud
 *   rather than a data-quality problem. Lines without one stay unsupported and
 *   appear on the eTIMS gap report, which is exactly where they belong.
 */
class BackfillTaxDocumentCommand extends Command
{
    protected $signature = 'finance:backfill-tax-document
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Backfill tax point dates and supplier PINs onto cost lines verified before they were captured';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $supplierTypeIds = DB::table('payee_types')
            ->where('requires_supplier_record', true)
            ->pluck('id')
            ->all();

        $pins = Supplier::query()
            ->whereNotNull('kra_pin')
            ->pluck('kra_pin', 'id');

        $taxPoints = 0;
        $pinned = 0;
        $storesLines = 0;

        CostLine::query()
            ->whereIn('nature', [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL])
            ->where(function ($q) {
                $q->whereNull('tax_point_date')->orWhereNull('supplier_pin');
            })
            ->orderBy('id')
            ->chunkById(500, function ($lines) use (&$taxPoints, &$pinned, &$storesLines, $dry, $supplierTypeIds, $pins) {
                foreach ($lines as $line) {
                    $changes = [];

                    if (! $line->tax_point_date && $line->incurred_at) {
                        $changes['tax_point_date'] = $line->incurred_at->toDateString();
                        $taxPoints++;

                        if (in_array($line->source_ref, ['stock-issue', 'stock-return'], true)) {
                            $storesLines++;
                        }
                    }

                    if (! $line->supplier_pin
                        && $line->payee_id
                        && in_array($line->payee_type_id, $supplierTypeIds, true)
                        && isset($pins[$line->payee_id])) {
                        $changes['supplier_pin'] = $pins[$line->payee_id];
                        $pinned++;
                    }

                    if ($changes && ! $dry) {
                        $line->forceFill($changes)->save();
                    }
                }
            });

        $verb = $dry ? 'would be' : 'were';

        $this->info("{$taxPoints} tax point date(s) {$verb} set from the incurred date.");
        $this->info("{$pinned} supplier PIN(s) {$verb} snapshotted from the supplier record.");

        if ($storesLines) {
            $this->warn(
                "{$storesLines} of those are Stores movements, where the incurred date is when the job "
                . 'consumed the material, not when it was bought. Their tax points are approximate — '
                . 'check any that appear near a claim deadline.'
            );
        }

        $this->newLine();
        $this->line('eTIMS invoice numbers were not inferred and never will be. '
            . 'Run the eTIMS gap report to see which claims still cannot be substantiated.');

        if ($dry) {
            $this->comment('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
