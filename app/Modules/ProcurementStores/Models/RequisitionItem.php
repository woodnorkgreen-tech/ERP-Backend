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
        'quantity',
        'purpose',
        'reason',
    ];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function material()
    {
        return $this->belongsTo('App\Modules\MaterialsLibrary\Models\Material', 'material_id');
    }
}