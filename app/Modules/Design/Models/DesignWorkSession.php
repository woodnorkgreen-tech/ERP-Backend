<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignWorkSession extends Model
{
    protected $fillable = [
        'design_item_id', 'user_id', 'started_at', 'ended_at', 'end_type',
        'pause_reason_code', 'pause_reason_details', 'ended_by',
    ];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

    public function item(): BelongsTo { return $this->belongsTo(DesignItem::class, 'design_item_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function endedBy(): BelongsTo { return $this->belongsTo(User::class, 'ended_by'); }
}
