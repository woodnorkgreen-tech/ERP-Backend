<?php

namespace App\Modules\Finance\CostCollector\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Return-for-Correction cycle of a W7 labour actual.
 *
 * Append-only. A row is written when the actual is returned and completed once,
 * when it is resubmitted (resubmitted_by/at and the field-level changes). After
 * that it can never change, and it can never be deleted — the actual's own
 * returned_* columns are overwritten by the next cycle, so this table is the
 * only complete history of who returned what, why, and what the recorder changed.
 */
class ProjectLabourActualReturn extends Model
{
    private const RESUBMISSION_FIELDS = ['resubmitted_by', 'resubmitted_at', 'changes', 'updated_at'];

    protected $fillable = [
        'project_labour_actual_id',
        'cycle',
        'returned_from_status',
        'returned_by',
        'returned_at',
        'return_reason',
        'snapshot_before',
        'resubmitted_by',
        'resubmitted_at',
        'changes',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'returned_at' => 'datetime',
        'resubmitted_at' => 'datetime',
        'snapshot_before' => 'array',
        'changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            $dirty = array_keys($row->getDirty());
            if ($row->getOriginal('resubmitted_at') !== null
                || array_diff($dirty, self::RESUBMISSION_FIELDS) !== []) {
                throw new LogicException('Labour return history is immutable.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Labour return history cannot be deleted.');
        });
    }

    public function actual(): BelongsTo
    {
        return $this->belongsTo(ProjectLabourActual::class, 'project_labour_actual_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function resubmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resubmitted_by');
    }
}
