<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RequisitionItem extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'requisition_id',
        'material_id',
        'custom_description',
        'quantity',
        'unit_price',
        'total',
        'purpose',
        'reason',
    ];
    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
    ];
    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    /**
     * FIXED: Changed from 'Material' to 'LibraryMaterial'
     */
    public function material()
    {
        return $this->belongsTo('App\Modules\MaterialsLibrary\Models\LibraryMaterial', 'material_id');
    }
}
