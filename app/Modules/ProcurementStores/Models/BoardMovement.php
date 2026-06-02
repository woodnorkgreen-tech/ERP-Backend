<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class BoardMovement extends Model
{
    protected $table = 'board_movements';

    // ts is our immutable timestamp; no updated_at column exists
    const CREATED_AT = 'ts';
    const UPDATED_AT = null;

    protected $fillable = [
        'board_id',
        'from_status',
        'to_status',
        'job_ref',
        'performed_by',
        'notes',
        'condition_grade',
        'scrap_reason_code',
        'ts',
    ];

    protected $casts = [
        'ts' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
