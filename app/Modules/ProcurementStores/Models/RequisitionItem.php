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
        'project_enquiry_id',
        'procurement_task_id',
        'budget_data_id',
        'budget_element_id',
        'budget_element_persistent_id',
        'budget_item_id',
        'budget_item_persistent_id',
        'material_id',
        'expense_code_id',
        'custom_description',
        'quantity',
        'unit_price',
        'internal_budget_unit_price',
        'total',
        'purpose',
        'reason',
        'procurement_item_snapshot',
    ];
    protected $casts = [
        'unit_price' => 'decimal:2',
        'internal_budget_unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'project_enquiry_id' => 'integer',
        'procurement_task_id' => 'integer',
        'budget_data_id' => 'integer',
        'procurement_item_snapshot' => 'array',
    ];
    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * FIXED: Changed from 'Material' to 'LibraryMaterial'
     */
    public function material()
    {
        return $this->belongsTo('App\Modules\MaterialsLibrary\Models\LibraryMaterial', 'material_id');
    }

    public function expenseCode()
    {
        return $this->belongsTo(\App\Modules\Finance\CostCollector\Models\ExpenseCode::class);
    }
}
