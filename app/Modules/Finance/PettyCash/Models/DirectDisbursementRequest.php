<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;

class DirectDisbursementRequest extends Model
{
    protected $fillable = [
        'idempotency_key', 'status', 'payload', 'requested_by', 'approved_by',
        'approved_at', 'disbursement_id', 'rejection_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'approved_at' => 'datetime',
    ];
}
