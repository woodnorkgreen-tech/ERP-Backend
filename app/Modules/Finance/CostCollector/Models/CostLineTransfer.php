<?php

namespace App\Modules\Finance\CostCollector\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W6-4: Groups the three cost lines produced by a transfer/reclassification.
 *
 * A transfer moves a verified cost from Project A to Project B via a reversing
 * pair. This record exists solely for audit trail: it links the source line to
 * the OUT (negative, source project) and IN (positive, destination project)
 * lines so Finance can trace any reclassification to its origin.
 *
 * The original source cost line is NEVER modified — its project_enquiry_id
 * stays put. Only the OUT/IN pair produce the accounting effect.
 */
class CostLineTransfer extends Model
{
    /** W6-4: cost moved from one project to another. */
    public const TYPE_RECLASSIFICATION = 'reclassification';

    /** W7-13: same-project correction — OUT negates the original, IN carries the corrected amount. */
    public const TYPE_CORRECTION = 'correction';

    protected $fillable = [
        'source_cost_line_id',
        'out_cost_line_id',
        'in_cost_line_id',
        'transfer_type',
        'reason',
        'transferred_by',
    ];

    public function sourceLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'source_cost_line_id');
    }

    public function outLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'out_cost_line_id');
    }

    public function inLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'in_cost_line_id');
    }
}
