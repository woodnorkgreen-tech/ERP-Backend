<?php

namespace App\Modules\Printing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintWorkSession extends Model
{
    protected $fillable = [
        'print_job_id', 'user_id', 'started_at', 'ended_at', 'end_type',
        'pause_reason_code', 'pause_reason_details', 'ended_by',
    ];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

    public function job(): BelongsTo { return $this->belongsTo(PrintJob::class, 'print_job_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function endedBy(): BelongsTo { return $this->belongsTo(User::class, 'ended_by'); }
}
