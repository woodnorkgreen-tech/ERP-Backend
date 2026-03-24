<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollVariable extends Model
{
    protected $fillable = [
        'name',
        'type',
        'value',
        'is_active',
        'description'
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'is_active' => 'boolean'
    ];
}
