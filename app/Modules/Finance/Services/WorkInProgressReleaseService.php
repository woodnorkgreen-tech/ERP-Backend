<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Support\ChartAccountMap;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves a job's accumulated costs out of Work in Progress and into Cost of
 * Sales, at the moment the job is billed.
 *
 * ## What problem this solves
 *
 * Every cost charged to a job debits a Work in Progress account — an ASSET,
 * meaning "money spent on a job that is not finished". That is correct while the
 * job runs. But nothing ever moved it out, so the reference chart's nine Cost of
 * Sales accounts had never been touched by anything, and a completed job's costs
 * sat on the balance sheet for ever as though WNG still owned them.
 *
 * The consequence is that no job could show a profit. Revenue landed in the
 * month it was billed; the cost of earning it stayed an asset indefinitely. The
 * expense catalogue's own note says the transfer should happen — *"Post to
 * Project WIP while the job is open, then transfer to cost of sales when the
 * related revenue is recognised"* — and no code did it.
 *
 * ## Why the release is tied to billing, not to a date
 *
 * Matching is the point. The cost of a job has to land in the same period as the
 * revenue it earned, or a job that spans a month end shows a loss in one month
 * and an inflated profit in the next. Measured on WNG's own data, 21% of jobs
 * cross a month end, so this is a fifth of the business rather than an edge case.
 *
 * ## Proportional release, and why it converges
 *
 * A job billed in two parts must release its costs in two parts. The rule is:
 *
 *     target released  = every cost ever charged to the job × share of the
 *                        agreed price invoiced so far
 *     release now      = target released − what has already been released
 *
 * Stated against the ORIGINAL cost total rather than the remaining balance, so
 * repeated calls converge on the right answer instead of releasing a fraction of
 * a fraction. Fully billed means fully released, whatever route got there.
 */
class WorkInProgressReleaseService
{
    /**
     * Work in Progress account => the Cost of Sales account it releases into.
     *
     * Both sides are reference codes resolved through `ChartAccountMap`, and the
     * pairing is the chart's own: 1211–1219 were created as the asset twin of
     * 5100–5900, family for family. Keeping the families apart through the
     * release is what lets a finished job still say what it spent on materials
     * as against subcontractors.
     */
    private const RELEASE_MAP = [
        '1211' => '5100',   // direct materials
        '1212' => '5200',   // direct labour
        '1213' => '5300',   // subcontractors
        '1214' => '5400',   // transport and logistics
        '1215' => '5500',   // equipment and site
        '1216' => '5600',   // project utilities
        '1217' => '5700',   // project facilitation
        '1218' => '5800',   // venue and statutory
        '1219' => '5900',   // rework and warranty
    ];

    public function __construct(private JournalPostingService $posting)
    {
    }

    /**
     * Release the share of a job's costs that its billing has now earned.
     *
     * Returns null when there is nothing to release — an unbilled job, a job
     * with no costs, or one already fully released. That is the ordinary case
     * for a second invoice on a job whose costs were all released by the first,
     * so it is a quiet no-op rather than an error.
     */
    public function releaseForInvoice(ProjectInvoice $invoice, ?int $actorId = null): ?JournalEntry
    {
        $enquiryId = (int) $invoice->project_enquiry_id;

        $fraction = $this->billedFraction($enquiryId);

        if (bccomp($fraction, '0.000000', 6) <= 0) {
            return null;
        }

        $legs = [];
        $total = '0.00';

        foreach (self::RELEASE_MAP as $wipCode => $costCode) {
            $wipAccount = $this->accountId($wipCode);
            $costAccount = $this->accountId($costCode);

            // A chart that carries neither account for a family simply has no
            // costs in it. One without the other is a mapping error worth
            // refusing, because releasing into nothing would lose the cost.
            if (! $wipAccount) {
                continue;
            }

            if (! $costAccount) {
                throw new InvalidArgumentException(
                    "Work in Progress account {$wipCode} has no matching Cost of Sales account "
                    . "({$costCode}) in this chart, so this job's costs cannot be released. "
                    . 'Finance must create it or map it in config/finance_accounts.php.'
                );
            }

            $movement = $this->movementOn($wipAccount, $enquiryId);

            $target = $this->money(bcmul($movement['cost'], $fraction, 6));
            $release = bcsub($target, $movement['released'], 2);

            if (bccomp($release, '0.00', 2) <= 0) {
                continue;
            }

            $legs[] = [
                'account_id' => $costAccount,
                'entry_type' => 'debit',
                'amount' => $release,
                'description' => 'Cost of sales on ' . $invoice->invoice_number,
                'project_enquiry_id' => $enquiryId,
            ];
            $legs[] = [
                'account_id' => $wipAccount,
                'entry_type' => 'credit',
                'amount' => $release,
                'description' => 'Work in progress released to cost of sales',
                'project_enquiry_id' => $enquiryId,
            ];

            $total = bcadd($total, $release, 2);
        }

        if ($legs === []) {
            return null;
        }

        return $this->posting->postBalancedEntry(
            entryNo: $this->entryNoFor($invoice),
            postingDate: $invoice->invoice_date->toDateString(),
            sourceType: ProjectInvoice::class,
            sourceId: $invoice->id,
            sourceRef: $invoice->invoice_number,
            description: 'Cost of sales released against ' . $invoice->invoice_number,
            legs: $legs,
            createdBy: $actorId,
        );
    }

    /**
     * How much of this job's agreed price has been billed, as a fraction of one.
     *
     * Capped at one. A job billed beyond its agreed price — which the invoice
     * cap should prevent, but which historic data may still contain — must not
     * release more cost than it incurred.
     *
     * Void invoices are excluded; they bill nothing.
     */
    public function billedFraction(int $enquiryId): string
    {
        $agreed = (float) DB::table('quote_approvals')
            ->where('enquiry_id', $enquiryId)
            ->where('approval_status', 'approved')
            ->orderByDesc('updated_at')
            ->value('quote_amount');

        $invoiced = (float) DB::table('project_invoices')
            ->where('project_enquiry_id', $enquiryId)
            ->whereNot('status', 'void')
            ->whereNotNull('journal_entry_id')
            ->sum('total_amount');

        if ($agreed <= 0 || $invoiced <= 0) {
            return '0.000000';
        }

        $fraction = bcdiv($this->money($invoiced), $this->money($agreed), 6);

        return bccomp($fraction, '1.000000', 6) > 0 ? '1.000000' : $fraction;
    }

    /**
     * What this job put INTO one Work in Progress account, and what releases
     * have already taken OUT of it.
     *
     * Both figures are NET of reversals, and that is the whole point of the
     * split. This used to return gross debits as "the cost" and gross credits as
     * "already released", which is only true while nothing is ever reversed. A
     * reversal breaks it in both directions:
     *
     *   - Reversing a RELEASE writes a debit to Work in Progress. Counted as
     *     cost, it inflates the base every future release is measured against —
     *     so voiding an invoice and re-billing the same job released less than
     *     the job actually cost.
     *   - Reversing a COST writes a credit. Counted as a release, it looked like
     *     cost had already been moved out that never was.
     *
     * Neither shows up while a job is billed once and nothing is corrected,
     * which is why it survived: the arithmetic only diverges at partial billing
     * after a reversal.
     *
     * The two are told apart by `source_type`. On a Work in Progress account the
     * only entries sourced from a ProjectInvoice are this service's own releases
     * and their reversals — revenue and allocation entries carry the same source
     * but never touch these accounts, and every cost producer posts under its own
     * document type. Reversals inherit `source_type` from the entry they reverse,
     * so each stays on the side it belongs to.
     *
     * @return array{cost: string, released: string}
     */
    private function movementOn(int $accountId, int $enquiryId): array
    {
        $release = ProjectInvoice::class;

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $accountId)
            ->where('jl.project_enquiry_id', $enquiryId)
            ->whereIn('je.status', ['posted', 'reversed'])
            // A NULL source_type fails the equality and falls to the ELSE, which
            // is the correct home for it: an entry with no document behind it is
            // not one of this service's releases.
            ->selectRaw(
                "SUM(CASE WHEN je.source_type = ? THEN 0"
                . " WHEN jl.entry_type = 'debit' THEN jl.base_amount ELSE -jl.base_amount END) as cost",
                [$release],
            )
            ->selectRaw(
                "SUM(CASE WHEN je.source_type <> ? THEN 0"
                . " WHEN jl.entry_type = 'credit' THEN jl.base_amount ELSE -jl.base_amount END) as released",
                [$release],
            )
            ->first();

        return [
            'cost' => $this->money($row->cost ?? 0),
            'released' => $this->money($row->released ?? 0),
        ];
    }

    /**
     * The entry number a release against this invoice carries.
     *
     * One release entry per invoice, so the number is derived rather than
     * searched for — which is also what makes the release idempotent and what
     * lets `reverseForInvoice` find the entry to undo.
     */
    private function entryNoFor(ProjectInvoice $invoice): string
    {
        return 'JE-WIP-' . str_pad((string) $invoice->id, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Put this invoice's cost release back, because the invoice is being voided.
     *
     * Costs return to Work in Progress, where they belong while the job is
     * unbilled again. Returns null when there is nothing to undo — a draft
     * invoice, a job with no costs, or a release already reversed — because all
     * three are ordinary and none is a failure.
     */
    public function reverseForInvoice(ProjectInvoice $invoice, ?int $actorId, string $reason): ?JournalEntry
    {
        $entry = JournalEntry::where('entry_no', $this->entryNoFor($invoice))->first();

        if (! $entry || $entry->status !== 'posted') {
            return null;
        }

        return $this->posting->reverseEntry($entry, $actorId, $reason);
    }

    private function accountId(string $referenceCode): ?int
    {
        $id = ChartOfAccount::postable()
            ->where('code', ChartAccountMap::local($referenceCode))
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function money(string|float|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
