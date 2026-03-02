<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\HR\Models\Employee;

class PettyCashRequisitionItem extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_requisition_items';

    protected $fillable = [
        'requisition_id',
        'description',
        'remarks',
        'amount',
        'payee_id',
        'payee_name',
        'payee_phone',
        'digital_signature',
        'received_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the requisition this item belongs to.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    /**
     * Get the payee for this item.
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payee_id');
    }
}
