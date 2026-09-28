<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignChangeRequest extends Model
{
    protected $fillable = ['design_item_id', 'against_revision_id', 'addressed_by_revision_id', 'request_text', 'requested_by_name', 'received_at', 'status', 'recorded_by'];
    protected $casts = ['received_at' => 'datetime'];
    public function item(): BelongsTo { return $this->belongsTo(DesignItem::class, 'design_item_id'); }
    public function againstRevision(): BelongsTo { return $this->belongsTo(DesignRevision::class, 'against_revision_id'); }
    public function addressedByRevision(): BelongsTo { return $this->belongsTo(DesignRevision::class, 'addressed_by_revision_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
