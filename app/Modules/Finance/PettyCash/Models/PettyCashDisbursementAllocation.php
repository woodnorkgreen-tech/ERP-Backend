<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PettyCashDisbursementAllocation extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_disbursement_allocations';

    protected $fillable = [
        'disbursement_id',
        'top_up_id',
        'amount',
        'transaction_cost',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_cost' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
