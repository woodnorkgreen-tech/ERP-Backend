<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnConfirmationLink extends Model
{
    protected $table = 'logistics_return_confirmation_links';

    protected $fillable = ['logistics_task_id', 'token', 'expires_at', 'revoked_at', 'confirmed_by', 'confirmed_by_name', 'confirmed_at', 'created_by'];
    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function logisticsTask(): BelongsTo { return $this->belongsTo(LogisticsTask::class); }
    public function confirmer(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'confirmed_by'); }
    public function creator(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'created_by'); }
    public function isAvailable(): bool { return !$this->revoked_at && (!$this->expires_at || $this->expires_at->isFuture()); }
}
