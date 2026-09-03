<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ElementMaterial;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Claims spend that was posted before its budget line existed.
 *
 * `StoresCostProducer` matches a stock issue to its planned line at the moment
 * it posts, which is the one moment the match can be impossible: issue the
 * material on Tuesday, project the budget on Wednesday, and the cost is recorded
 * unbudgeted forever. Nothing went back to connect them.
 *
 * The consequence is not cosmetic. The cost account then reports that spend as
 * unplanned AND the budget line as unanswered — the same money described two
 * contradictory ways on one screen — while the drawdown cap in
 * `CostVerificationService` still sees the full budget as available, so the line
 * can be spent twice over without anything flagging it.
 *
 * So this runs once a budget has finished projecting, and asks the question from
 * the cost's side: of the budget lines on this project, is there exactly one this
 * cost could belong to? Asking it from the budget's side instead — as each line
 * is created — would let the first line projected claim a cost before the line
 * that rivals it exists, which is precisely the "whichever matched first wins"
 * behaviour that once charged two MDF issues to another project's budget.
 *
 * Anything ambiguous is left unbudgeted for a person to code. That is the honest
 * answer, and the one the old blind fallback hid.
 */
class UnbudgetedSpendAdopter
{
    /**
     * Link every orphan cost on a project to the budget line it always belonged
     * to. Safe to re-run: a cost that already claims a line is never revisited.
     *
     * @return int the number of costs claimed
     */
    public function adoptForEnquiry(?int $enquiryId): int
    {
        if (! $enquiryId) {
            return 0;
        }

        $planned = $this->plannedLines($enquiryId);

        if ($planned->isEmpty()) {
            return 0;
        }

        $claimed = 0;

        foreach ($this->orphans($enquiryId) as $orphan) {
            $match = $this->soleMatchFor($orphan, $planned);

            if ($match) {
                $this->link($orphan, $match);
                $claimed++;
            }
        }

        return $claimed;
    }

    /**
     * The budget line this cost belongs to, or null where that is not certain.
     *
     * Identity is tried in descending order of authority: the exact project
     * material line first, then the catalogue item within the same element. A
     * stronger identity that matches several lines is ambiguous and stops there
     * — falling through to the weaker one could only widen the net.
     *
     * @param  Collection<int, CostLine>  $planned
     */
    private function soleMatchFor(CostLine $orphan, Collection $planned): ?CostLine
    {
        $keys = $this->materialKeys($orphan);

        if ($keys) {
            $byLine = $planned->filter(fn (CostLine $p) => in_array(
                (string) ($p->details['project_material_id'] ?? ''), $keys, true,
            ));

            if ($byLine->isNotEmpty()) {
                return $byLine->count() === 1 ? $byLine->first() : null;
            }
        }

        $library = $orphan->details['library_material_id'] ?? null;
        $element = $orphan->details['element'] ?? null;

        if (blank($library) || blank($element)) {
            return null;
        }

        // The element qualifier is what stops one stand's issue being charged to
        // another's: the same board legitimately appears under several elements.
        $byCatalogue = $planned->filter(fn (CostLine $p) => (string) ($p->details['library_material_id'] ?? '') === (string) $library
            && (string) ($p->details['element'] ?? '') === (string) $element);

        return $byCatalogue->count() === 1 ? $byCatalogue->first() : null;
    }

    /**
     * The two sides key the same project material line differently: the budget
     * projector writes `element_materials.persistent_id` (a UUID), the stores
     * movement carries `element_materials.id` (an integer). Both notations are
     * resolved so the comparison can actually match — comparing them directly is
     * what pushed every issue down to the weaker identity.
     *
     * @return array<int, string>
     */
    private function materialKeys(CostLine $line): array
    {
        $key = $line->details['project_material_id'] ?? null;

        if (blank($key)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', array_filter([
            $key,
            ElementMaterial::whereKey($key)->value('persistent_id'),
            ElementMaterial::where('persistent_id', $key)->value('id'),
        ]))));
    }

    /**
     * The project's materials budget. Labour, expenses and logistics name no
     * material, so there is nothing to match a cost against and a guess would be
     * worse than the gap.
     *
     * @return Collection<int, CostLine>
     */
    private function plannedLines(int $enquiryId): Collection
    {
        return CostLine::query()
            ->where('project_enquiry_id', $enquiryId)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->counting()
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category')) = 'materials'")
            ->get();
    }

    /**
     * Costs on this project that never claimed a budget line.
     *
     * Rejected and reversed lines are excluded: they are closed facts, and
     * `reclassify` refuses them for the same reason. Pending lines are included
     * on purpose — a cost still awaiting verification should reach its verifier
     * already coded to the right budget, rather than be shown to them as
     * unplanned and re-coded by hand.
     *
     * @return Collection<int, CostLine>
     */
    private function orphans(int $enquiryId): Collection
    {
        return CostLine::query()
            ->where('project_enquiry_id', $enquiryId)
            ->where('nature', '!=', CostLine::NATURE_PLANNED)
            ->whereNull('consumes_line_id')
            ->whereNotIn('status', [CostLine::STATUS_REJECTED, CostLine::STATUS_REVERSED])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category')) = 'materials'")
            ->get();
    }

    /**
     * Record the link as what it is: the system completing a match it would have
     * made at posting time, not a person re-coding a cost.
     *
     * Kept under its own `adoptions` key rather than folded into
     * `reclassifications`, which carry a `by` user and are read as somebody's
     * decision. Attributing this to whoever happened to save the budget would put
     * a name against a judgement they never made.
     *
     * The budget snapshot is recomputed for the same reason it is on a
     * reclassification: the stored figures describe a draw against a line this
     * cost did not previously claim, and leaving them would misreport what was
     * left at the moment of the decision.
     */
    private function link(CostLine $orphan, CostLine $planned): void
    {
        DB::transaction(function () use ($orphan, $planned) {
            $orphan = CostLine::whereKey($orphan->id)->lockForUpdate()->firstOrFail();

            // Re-checked inside the lock: a verifier could have coded this cost
            // between the match and the write.
            if ($orphan->consumes_line_id !== null) {
                return;
            }

            $meta = $orphan->capture_meta ?? [];
            $meta['adoptions'][] = [
                'to' => $planned->id,
                'reason' => 'Budget line projected after this cost posted; linked automatically on exact material identity.',
                'at' => now()->toIso8601String(),
            ];

            $details = (array) $orphan->details;
            // The line is no longer unbudgeted, and this flag is what drives the
            // "unplanned" badge on the cost account.
            unset($details['unbudgeted']);

            $drawn = (string) CostLine::where('consumes_line_id', $planned->id)
                ->counting()
                ->sum('net_amount');

            $before = bcsub((string) $planned->net_amount, $drawn ?: '0', 2);

            $orphan->forceFill([
                'consumes_line_id' => $planned->id,
                'capture_meta' => $meta,
                'details' => $details,
                'budget_remaining_before' => $before,
                'budget_remaining_after' => bcsub($before, (string) $orphan->net_amount, 2),
            ])->save();
        });
    }
}
