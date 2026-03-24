<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollTaxBand extends Model
{
    protected $fillable = [
        'name',
        'min_amount',
        'max_amount',
        'rate',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'rate' => 'decimal:4',
        'is_active' => 'boolean'
    ];
}
