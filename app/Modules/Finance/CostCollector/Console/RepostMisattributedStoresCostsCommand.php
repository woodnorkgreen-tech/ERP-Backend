<?php

namespace App\Modules\Finance\CostCollector\Console;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs Stores cost lines that were posted against the wrong project.
 *
 * The producer used to hand inventory_logs.project_id — a Projects primary key —
 * to the collector as an enquiry id. Where a ProjectEnquiry happened to share
 * that number, the collector resolved a coherent but entirely different project
 * and charged the cost to its budget line, while the job number Stores supplied
 * survived on the line and masked the swap.
 *
 * Finance is append-only, so nothing here is edited. Each bad line is reversed
 * by a signed opposite entry, and the movement is then reposted through the
 * corrected producer so the new line resolves its identity properly and consumes
 * the right budget line — or none, if the correct project has no matching
 * planned line, which is the honest outcome.
 *
 * Idempotent: a line already reversed by this command is skipped, and reposting
 * relies on the collector's own (source_type, source_id, source_ref) key.
 */
class RepostMisattributedStoresCostsCommand extends Command
{
    protected $signature = 'stores:repost-misattributed
        {--dry-run : Report what would change and write nothing}
        {--ref=* : Limit to specific cost line refs, e.g. --ref=CL-0023002}';

    protected $description = 'Reverse and repost Stores cost lines whose project identity contradicts itself';

    public function handle(StoresCostProducer $producer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $refs = (array) $this->option('ref');

        $candidates = CostLine::query()
            ->where('source_type', InventoryLog::class)
            ->where('source_ref', 'stock-issue')
            ->where('status', CostLine::STATUS_VERIFIED)
            ->when($refs, fn ($q) => $q->whereIn('ref', $refs))
            ->orderBy('id')
            ->get()
            ->filter(fn (CostLine $line) => $this->isMisattributed($line));

        if ($candidates->isEmpty()) {
            $this->info('No misattributed Stores cost lines found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing will be written.' : 'Applying reversal and repost.');
        $this->newLine();

        $reversed = 0;
        $reposted = 0;
        $skipped = 0;

        foreach ($candidates as $line) {
            $log = InventoryLog::find($line->source_id);

            if (! $log) {
                $this->warn("  {$line->ref}: source movement #{$line->source_id} is gone — skipped.");
                $skipped++;
                continue;
            }

            $correct = $this->correctIdentity($log);

            $this->line("  {$line->ref}  amount {$line->net_amount}");
            $this->line("      posted to   project {$line->project_id} / enquiry {$line->project_enquiry_id} / job {$line->job_number}"
                . ($line->consumes_line_id ? " / consumes #{$line->consumes_line_id}" : ''));
            $this->line("      belongs to  project {$correct['project_id']} / enquiry {$correct['project_enquiry_id']} / job {$correct['job_number']}");

            if ($this->alreadyReversed($line)) {
                $this->warn('      already reversed by a previous run — skipped.');
                $skipped++;
                continue;
            }

            // A return credit is derived from its issue line: it copies that
            // line's identity and its proportional value. Reversing the issue
            // without its credits would leave the credits pointing at a reversed
            // parent, so they travel together.
            $credits = $this->creditsFor($line);

            foreach ($credits as $credit) {
                $this->line("      + return credit {$credit->ref} ({$credit->net_amount}) rides on this line");
            }

            if ($dryRun) {
                $this->comment('      would reverse' . ($credits->isEmpty() ? '' : ' with ' . $credits->count() . ' credit(s)')
                    . ', then repost movement #' . $log->id);
                $reversed += 1 + $credits->count();
                $reposted += 1 + $credits->count();
                continue;
            }

            DB::transaction(function () use ($line, $log, $credits, $producer, &$reversed, &$reposted) {
                // Credits first, so no moment exists where a live credit points
                // at a reversed issue.
                foreach ($credits as $credit) {
                    $this->reverse($credit, 'stock-return-reversal');
                    $credit->forceFill(['source_ref' => 'stock-return-misattributed'])->save();
                    $reversed++;
                }

                $this->reverse($line);
                $reversed++;

                // Clear the producer's idempotency key for this movement so the
                // corrected line can be written. The reversal above preserves
                // the original as audit evidence.
                $line->forceFill(['source_ref' => 'stock-issue-misattributed'])->save();

                if ($new = $producer->postStockIssue($log->fresh())) {
                    $reposted++;
                    $this->info("      reposted as {$new->ref}"
                        . ($new->consumes_line_id ? " consuming #{$new->consumes_line_id}" : ' (UNBUDGETED)'));
                }

                // Repost each credit against the corrected issue line.
                foreach ($credits as $credit) {
                    $returnLog = InventoryLog::find($credit->source_id);
                    if ($returnLog && $recredit = $producer->postStockReturn($returnLog)) {
                        $reposted++;
                        $this->info("      return credit reposted as {$recredit->ref}");
                    }
                }

                StoresFinancePosting::whereIn('inventory_log_id', $credits->pluck('source_id')->push($log->id))
                    ->update(['resolution_notes' => 'Reposted by stores:repost-misattributed on ' . now()->toDateString()]);
            });
        }

        $this->newLine();
        $this->line($dryRun
            ? "{$reversed} to reverse, {$reposted} to repost, {$skipped} skipped. No changes written."
            : "{$reversed} reversed, {$reposted} reposted, {$skipped} skipped.");

        if ($dryRun) {
            $this->newLine();
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /** A line is misattributed when its job number does not belong to its resolved project. */
    private function isMisattributed(CostLine $line): bool
    {
        $project = $line->project_id ? Project::find($line->project_id) : null;
        $enquiry = $line->project_enquiry_id ? ProjectEnquiry::find($line->project_enquiry_id) : null;

        if ($project && $enquiry && (int) $project->enquiry_id !== (int) $enquiry->id) {
            return true;
        }

        if (blank($line->job_number) || (! $project && ! $enquiry)) {
            return false;
        }

        $accepted = array_values(array_filter([
            $enquiry?->job_number,
            $project?->enquiry?->job_number,
            $project?->project_id,
        ]));

        if (! $accepted) {
            return false;
        }

        $normalise = fn (?string $value) => PettyCashCostProducer::normaliseJobNumber((string) $value);
        $supplied = $normalise($line->job_number);

        foreach ($accepted as $candidate) {
            if ($normalise($candidate) === $supplied) {
                return false;
            }
        }

        return true;
    }

    /** @return array{project_id: ?int, project_enquiry_id: ?int, job_number: ?string} */
    private function correctIdentity(InventoryLog $log): array
    {
        $project = $log->project_id ? Project::with('enquiry')->find($log->project_id) : null;

        return [
            'project_id' => $project?->id,
            'project_enquiry_id' => $project?->enquiry_id,
            'job_number' => $project?->enquiry?->job_number ?: $project?->project_id ?: $log->reference_no,
        ];
    }

    private function alreadyReversed(CostLine $line): bool
    {
        return CostLine::where('reversal_of_id', $line->id)
            ->whereIn('source_ref', ['stock-issue-reversal', 'stock-return-reversal'])
            ->exists();
    }

    /** Verified return credits that were derived from this issue line. */
    private function creditsFor(CostLine $line): \Illuminate\Support\Collection
    {
        return CostLine::where('source_type', InventoryLog::class)
            ->where('source_ref', 'stock-return')
            ->where('status', CostLine::STATUS_VERIFIED)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.original_cost_line_id')) = ?", [(string) $line->id])
            ->orderBy('id')
            ->get();
    }

    private function reverse(CostLine $line, string $sourceRef = 'stock-issue-reversal'): CostLine
    {
        $negative = '-' . ltrim((string) $line->net_amount, '-');

        $reversal = $line->replicate([
            'ref', 'created_at', 'updated_at', 'verified_at',
        ]);

        $reversal->forceFill([
            'ref' => 'PENDING',
            'amount' => $negative,
            'net_amount' => $negative,
            'base_net_amount' => bcmul($negative, (string) ($line->fx_rate ?? 1), 2),
            'reversal_of_id' => $line->id,
            'source_ref' => $sourceRef,
            'status' => CostLine::STATUS_VERIFIED,
            'verified_at' => now(),
            'description' => 'Reversal (misattributed project): ' . $line->description,
            'details' => array_merge((array) $line->details, [
                'reversal_of_ref' => $line->ref,
                'reversal_reason' => 'Posted against the wrong project by the pre-fix Stores producer.',
            ]),
        ])->save();

        $reversal->forceFill(['ref' => 'CL-' . str_pad((string) $reversal->id, 7, '0', STR_PAD_LEFT)])->save();

        return $reversal;
    }
}
