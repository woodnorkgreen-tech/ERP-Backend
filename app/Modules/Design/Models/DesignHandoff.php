<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignHandoff extends Model
{
    protected $fillable = [
        'design_item_id',
        'target_module',
        'target_record_id',
        'status',
        'payload_snapshot',
        'rejection_reason',
        'handed_off_by',
        'responded_by',
        'responded_at',
        'handed_off_at',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'handed_off_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(DesignItem::class, 'design_item_id');
    }

    public function handedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_off_by');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
