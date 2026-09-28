<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DesignRevision extends Model
{
    protected $fillable = ['design_item_id', 'version_number', 'change_summary', 'status', 'created_by', 'approved_by', 'approval_evidence', 'approved_at', 'handed_off_at'];
    protected $casts = ['approved_at' => 'datetime', 'handed_off_at' => 'datetime'];
    public function item(): BelongsTo { return $this->belongsTo(DesignItem::class, 'design_item_id'); }
    public function documents(): HasMany { return $this->hasMany(DesignDocument::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
