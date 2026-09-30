<?php

namespace App\Modules\Finance\CostCollector\Models;

use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * W6-3: A slice of a shared cost line allocated to a specific project.
 *
 * The service enforces: SUM(allocated_amount) across all allocations for a
 * given cost_line_id must equal the parent CostLine's net_amount. The DB
 * unique constraint prevents double-allocation of the same line to the same
 * project. The parent cost line is excluded from its own project's margin once
 * any allocations exist; only the slices appear in each recipient project.
 */
class CostLineAllocation extends Model
{
    protected $fillable = [
        'cost_line_id',
        'project_enquiry_id',
        'allocated_amount',
        'allocated_by',
        'allocation_reason',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function costLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class);
    }

    public function projectEnquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class);
    }
}
