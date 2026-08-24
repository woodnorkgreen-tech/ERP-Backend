<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashOfflineBatch extends Model
{
    protected $fillable = ['batch_reference', 'workbook_version', 'original_filename', 'file_sha256', 'status', 'totals', 'validation_summary', 'uploaded_by', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'rejection_reason', 'failure_reason'];
    protected $casts = ['totals' => 'array', 'validation_summary' => 'array', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    public function rows(): HasMany { return $this->hasMany(PettyCashOfflineRow::class, 'batch_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'uploaded_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'approved_by'); }
}
