<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;

class PettyCashCustodyHandover extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['system_balance' => 'decimal:2', 'physical_cash' => 'decimal:2', 'variance' => 'decimal:2', 'confirmed_at' => 'datetime'];
}
