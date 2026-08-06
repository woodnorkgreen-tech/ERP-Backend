<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'name', 'dimension', 'decimal_places', 'allows_fraction', 'is_active'];
    protected $casts = ['decimal_places' => 'integer', 'allows_fraction' => 'boolean', 'is_active' => 'boolean'];
}
