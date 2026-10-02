<?php

namespace App\Modules\Design\Models;

use Illuminate\Database\Eloquent\Model;

class DesignPrintOption extends Model
{
    protected $fillable = ['kind', 'label', 'bleed_per_side_m'];

    protected $casts = ['bleed_per_side_m' => 'decimal:3'];
}
