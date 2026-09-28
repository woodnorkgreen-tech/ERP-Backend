<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryAdvanceRecovery extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2', 'recovered_on' => 'date'];
}
