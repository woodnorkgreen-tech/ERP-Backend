<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\CostLineTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * W6-4: Cost Transfer / Reclassification — and, per W7-13, labour correction.
 *
 * Every economic change to a verified cost goes through a reversing pair:
 *   CL-TRF-OUT-xxxxx — negates the original on its own project, auto-verified.
 *   CL-TRF-IN-xxxxx  — the replacement amount, auto-verified.
 *
 * transfer() — cross-project reclassification: IN lands on the destination for the
 *   same amount. Net effect across all projects: unchanged.
 * correct()  — same-project correction (W7-13): IN lands on the same project for the
 *   corrected amount. Net effect on the project: corrected − original.
 *
 * The original cost line is NEVER modified. Its project and status stay put; only
 * the OUT/IN pair produce the accounting effect, so the history stays readable.
 * A cost line can be the source of at most one transfer or correction — enforced
 * under a row lock and by a unique index on source_cost_line_id.
 */
class CostTransferService
{
    /**
     * Transfer $line from its current project to $destination.
     *
     * @throws ValidationException
     */
    public function transfer(
        CostLine $line,
        ProjectEnquiry $destination,
        int $actorId,
        string $reason
    ): CostLineTransfer {
        if ($line->project_enquiry_id === null) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['This cost line has no source project.'],
            ]);
        }

        if ($line->project_enquiry_id === $destination->id) {
            throw ValidationException::withMessages([
                'destination_enquiry_id' => ['Source and destination project must differ.'],
            ]);
        }

        if ($destination->financial_closure_status === 'closed') {
            throw ValidationException::withMessages([
                'destination_enquiry_id' => ['Cannot transfer costs to a financially closed project.'],
            ]);
        }

        return DB::transaction(function () use ($line, $destination, $actorId, $reason) {
            // Lock and re-read the source so every guard below sees committed state
            // and a concurrent second transfer waits here, then fails the guard.
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertMovable($line);

            $amount = $this->amountOf($line);

            $out = $this->outLine($line, "Transfer out: {$reason}", $actorId);

            // IN line: positive, charges the destination project.
            $in = CostLine::create([
                'ref'                  => 'PENDING',
                'project_enquiry_id'   => $destination->id,
                'project_id'           => $destination->project_id ?? null,
                'job_number'           => $destination->job_number,
                'nature'               => CostLine::NATURE_ACTUAL,
                'status'               => CostLine::STATUS_VERIFIED,
                'expense_code_id'      => $line->expense_code_id,
                'amount'               => $amount,
                'tax_amount'           => '0.00',
                'net_amount'           => $amount,
                'base_net_amount'      => $amount,
                'currency'             => $line->currency,
                'fx_rate'              => $line->fx_rate,
                'incurred_at'          => $line->incurred_at,
                'description'          => "Transfer in: {$reason}",
                'source_type'          => CostLineTransfer::class,
                'submitted_by_user_id' => $actorId,
                'verified_at'          => now(),
                'details'              => $line->details,
            ]);
            $in->forceFill([
                'ref' => 'CL-TRF-IN-' . str_pad((string) $in->id, 5, '0', STR_PAD_LEFT),
            ])->save();

            return CostLineTransfer::create([
                'source_cost_line_id' => $line->id,
                'out_cost_line_id'    => $out->id,
                'in_cost_line_id'     => $in->id,
                'transfer_type'       => CostLineTransfer::TYPE_RECLASSIFICATION,
                'reason'              => $reason,
                'transferred_by'      => $actorId,
            ]);
        });
    }

    /**
     * W7-13: correct $line within its own project through the same reversing pair.
     *
     * OUT negates the original in full (and releases its budget-line consumption);
     * IN carries $correctedAmount on the same project and budget line. The project
     * then carries the corrected amount exactly once: original − original + corrected.
     *
     * Must run inside the caller's transaction when the caller has its own state to
     * update atomically (the W7 successor); it opens a nested transaction otherwise.
     *
     * @param  array<string, mixed>  $correctedDetails  details for the IN line
     * @throws ValidationException
     */
    public function correct(
        CostLine $line,
        string $correctedAmount,
        array $correctedDetails,
        int $actorId,
        string $reason,
    ): CostLineTransfer {
        if (! is_numeric($correctedAmount) || bccomp($correctedAmount, '0', 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['A corrected amount must be zero or more.'],
            ]);
        }

        return DB::transaction(function () use ($line, $correctedAmount, $correctedDetails, $actorId, $reason) {
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertMovable($line);

            $closure = DB::table('project_enquiries')->where('id', $line->project_enquiry_id)
                ->lockForUpdate()->value('financial_closure_status');
            if ($closure === 'closed') {
                throw ValidationException::withMessages([
                    'cost_line_id' => ['Cannot correct costs on a financially closed project.'],
                ]);
            }

            $amount = bcadd($correctedAmount, '0', 2);

            $out = $this->outLine($line, "Correction out: {$reason}", $actorId);

            $in = CostLine::create([
                'ref'                  => 'PENDING',
                'project_enquiry_id'   => $line->project_enquiry_id,
                'project_id'           => $line->project_id,
                'job_number'           => $line->job_number,
                'nature'               => CostLine::NATURE_ACTUAL,
                'status'               => CostLine::STATUS_VERIFIED,
                'expense_code_id'      => $line->expense_code_id,
                'amount'               => $amount,
                'tax_amount'           => '0.00',
                'net_amount'           => $amount,
                'base_net_amount'      => bcmul($amount, (string) ($line->fx_rate ?: '1'), 2),
                'currency'             => $line->currency,
                'fx_rate'              => $line->fx_rate,
                'incurred_at'          => $line->incurred_at,
                'description'          => "Correction in: {$reason}",
                'source_type'          => CostLineTransfer::class,
                'submitted_by_user_id' => $actorId,
                'verified_at'          => now(),
                'details'              => $correctedDetails,
                'consumes_line_id'     => $line->consumes_line_id,
            ]);
            $in->forceFill([
                'ref' => 'CL-TRF-IN-' . str_pad((string) $in->id, 5, '0', STR_PAD_LEFT),
            ])->save();

            return CostLineTransfer::create([
                'source_cost_line_id' => $line->id,
                'out_cost_line_id'    => $out->id,
                'in_cost_line_id'     => $in->id,
                'transfer_type'       => CostLineTransfer::TYPE_CORRECTION,
                'reason'              => $reason,
                'transferred_by'      => $actorId,
            ]);
        });
    }

    /** Guards shared by transfer and correction; $line must already be locked. */
    private function assertMovable(CostLine $line): void
    {
        if ($line->status !== CostLine::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['Only verified cost lines can be transferred.'],
            ]);
        }

        // An adjustment line is never re-moved — except the IN leg of a correction,
        // which is the cost's current authoritative line and may itself be corrected
        // or reclassified later. An OUT leg, or the IN leg of a reclassification, is not.
        if ($line->source_type === CostLineTransfer::class
            && ! CostLineTransfer::where('in_cost_line_id', $line->id)
                ->where('transfer_type', CostLineTransfer::TYPE_CORRECTION)->exists()) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['Transfer adjustment lines cannot be transferred.'],
            ]);
        }

        if (CostLineTransfer::where('source_cost_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['This cost line has already been transferred.'],
            ]);
        }

        if ($line->isAllocated()) {
            throw ValidationException::withMessages([
                'cost_line_id' => ['An allocated cost line cannot be transferred. Remove allocations first.'],
            ]);
        }
    }

    /** OUT line: negative, removes the cost (and its budget consumption) from the source project. */
    private function outLine(CostLine $line, string $description, int $actorId): CostLine
    {
        $outAmount = bcmul($this->amountOf($line), '-1', 2);

        $out = CostLine::create([
            'ref'                  => 'PENDING',
            'project_enquiry_id'   => $line->project_enquiry_id,
            'project_id'           => $line->project_id,
            'job_number'           => $line->job_number,
            'nature'               => CostLine::NATURE_ACTUAL,
            'status'               => CostLine::STATUS_VERIFIED,
            'expense_code_id'      => $line->expense_code_id,
            'amount'               => $outAmount,
            'tax_amount'           => '0.00',
            'net_amount'           => $outAmount,
            'base_net_amount'      => $outAmount,
            'currency'             => $line->currency,
            'fx_rate'              => $line->fx_rate,
            'incurred_at'          => $line->incurred_at,
            'description'          => $description,
            'source_type'          => CostLineTransfer::class,
            'submitted_by_user_id' => $actorId,
            'verified_at'          => now(),
            'details'              => $line->details,
            'consumes_line_id'     => $line->consumes_line_id,
        ]);
        $out->forceFill([
            'ref' => 'CL-TRF-OUT-' . str_pad((string) $out->id, 5, '0', STR_PAD_LEFT),
        ])->save();

        return $out;
    }

    /** The line's net amount as an exact 2dp string — never through a float. */
    private function amountOf(CostLine $line): string
    {
        return bcadd((string) $line->net_amount, '0', 2);
    }
}
