<?php
namespace App\Modules\Finance\PettyCash\Models;
use Illuminate\Database\Eloquent\Model;
use App\Modules\Finance\Models\Payment;
class RequisitionPaymentAllocation extends Model {
    protected $guarded = ['id'];
    protected $casts = ['allocated_amount' => 'decimal:2'];
    public function payment() { return $this->belongsTo(Payment::class); }
    public function item() { return $this->belongsTo(PettyCashRequisitionItem::class, 'requisition_item_id'); }
    public function requisition() { return $this->belongsTo(PettyCashRequisition::class); }
}
