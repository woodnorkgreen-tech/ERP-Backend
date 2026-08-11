<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialUomConversion extends Model
{
    protected $fillable = ['material_id', 'from_uom_id', 'to_uom_id', 'factor'];
    protected $casts = ['factor' => 'decimal:6'];
}
