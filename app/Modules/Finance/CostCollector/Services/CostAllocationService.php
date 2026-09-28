<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\CostLineAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W6-3: Shared Cost Allocation.
 *
 * A single verified cost line (e.g. a shared venue hire) can be split across
 * multiple projects by recording allocations. The sum invariant is enforced
 * here: SUM(allocated_amount) MUST equal the parent line's net_amount — no
 * doubling, no rounding gap.
 *
 * Once a line has allocations:
 *   - The parent is excluded from its own project's margin.
 *   - Each recipient project sees only its slice in margin.
 *
 * The invariant prevents the double-counting problem: e.g. a KES 45,000 shared
 * line split into 25,000 + 20,000 — each project sees its share, the total
 * stays at 45,000, not 90,000.
 */
class CostAllocationService
{
    /**
     * Create or replace the allocation set for a verified cost line.
     *
     * $slices: [['enquiry_id' => int, 'amount' => string|numeric], ...]
     *
     * @throws ValidationException
     */
    public function allocate(CostLine $line, array $slices, int $actorId, string $reason): void
    {
        if ($line->status !== CostLine::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['Only verified cost lines can be allocated.'],
            ]);
        }

        if ($line->source_type === \App\Modules\Finance\CostCollector\Models\CostLineTransfer::class) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['Transfer adjustment lines cannot be allocated.'],
            ]);
        }

        if (\App\Modules\Finance\CostCollector\Models\CostLineTransfer::where('source_cost_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['A transferred cost line cannot be allocated.'],
            ]);
        }

        if (count($slices) < 2) {
            throw ValidationException::withMessages([
                'slices' => ['At least two allocation slices are required.'],
            ]);
        }

        // Validate enquiry IDs exist.
        $enquiryIds = array_column($slices, 'enquiry_id');
        $found = ProjectEnquiry::whereIn('id', $enquiryIds)->pluck('id')->all();
        $missing = array_diff($enquiryIds, $found);
        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'slices' => ['Unknown project IDs: ' . implode(', ', $missing)],
            ]);
        }

        // Prevent allocating to financially closed projects.
        $closedProjects = ProjectEnquiry::whereIn('id', $enquiryIds)
            ->where('financial_closure_status', 'closed')
            ->pluck('job_number', 'id')
            ->all();

        if (! empty($closedProjects)) {
            throw ValidationException::withMessages([
                'slices' => ['Cannot allocate costs to financially closed project(s): ' . implode(', ', $closedProjects)],
            ]);
        }

        // Sum invariant: sum of slices must equal parent net_amount exactly.
        $total = array_reduce(
            $slices,
            fn ($carry, $slice) => bcadd($carry, (string) ($slice['amount'] ?? 0), 2),
            '0.00'
        );

        $parentAmount = number_format((float) $line->net_amount, 2, '.', '');

        if (bccomp($total, $parentAmount, 2) !== 0) {
            throw ValidationException::withMessages([
                'slices' => [
                    "Allocation slices sum to {$total} but the cost line total is {$parentAmount}. "
                    . 'The sum of allocations must exactly equal the parent line amount.',
                ],
            ]);
        }

        DB::transaction(function () use ($line, $slices, $actorId, $reason) {
            // Lock parent line for update to prevent concurrent duplicate allocations
            CostLine::where('id', $line->id)->lockForUpdate()->first();

            // Replace any existing allocation set atomically.
            CostLineAllocation::where('cost_line_id', $line->id)->delete();

            foreach ($slices as $slice) {
                CostLineAllocation::create([
                    'cost_line_id'       => $line->id,
                    'project_enquiry_id' => $slice['enquiry_id'],
                    'allocated_amount'   => number_format((float) ($slice['amount'] ?? 0), 2, '.', ''),
                    'allocated_by'       => $actorId,
                    'allocation_reason'  => $reason,
                ]);
            }
        });
    }

    /**
     * Remove all allocations from a line (returns it to direct project charging).
     */
    public function deallocate(CostLine $line, int $actorId): void
    {
        CostLineAllocation::where('cost_line_id', $line->id)->delete();
    }

    /**
     * Return the current allocation set for a line.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllocations(CostLine $line): array
    {
        return CostLineAllocation::where('cost_line_id', $line->id)
            ->with('projectEnquiry:id,job_number,title')
            ->get()
            ->map(fn ($a) => [
                'id'                 => $a->id,
                'project_enquiry_id' => $a->project_enquiry_id,
                'job_number'         => $a->projectEnquiry?->job_number,
                'title'              => $a->projectEnquiry?->title,
                'allocated_amount'   => (string) $a->allocated_amount,
                'allocation_reason'  => $a->allocation_reason,
                'allocated_by'       => $a->allocated_by,
                'allocated_at'       => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
