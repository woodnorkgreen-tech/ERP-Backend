<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashOfflineRow extends Model
{
    protected $fillable = ['batch_id', 'record_type', 'row_number', 'offline_reference', 'payload', 'errors', 'warnings', 'status', 'posted_type', 'posted_id'];
    protected $casts = ['payload' => 'array', 'errors' => 'array', 'warnings' => 'array'];
    public function batch(): BelongsTo { return $this->belongsTo(PettyCashOfflineBatch::class, 'batch_id'); }
}
