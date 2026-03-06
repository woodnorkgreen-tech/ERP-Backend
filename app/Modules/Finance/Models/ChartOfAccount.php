<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'name',
        'code',
        'category',
        'is_active',
    ];
}
