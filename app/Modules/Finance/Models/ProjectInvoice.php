<?php

namespace App\Modules\Finance\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProjectInvoice extends Model
{
    protected $fillable = ['invoice_number','project_enquiry_id','invoice_date','due_date','subtotal','tax_amount','total_amount','status','notes','created_by','issued_by','issued_at','voided_by','voided_at','void_reason'];
    protected $casts = ['invoice_date'=>'date','due_date'=>'date','subtotal'=>'decimal:2','tax_amount'=>'decimal:2','total_amount'=>'decimal:2','issued_at'=>'datetime','voided_at'=>'datetime'];
    public function enquiry(): BelongsTo { return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function payments(): BelongsToMany { return $this->belongsToMany(\App\Models\EnquiryPayment::class, 'project_invoice_allocations')->withPivot('amount','allocated_by')->withTimestamps(); }
}
