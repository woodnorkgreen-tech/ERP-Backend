<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashSurrenderItem extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_surrender_items';

    protected $fillable = [
        'requisition_id',
        'expense_code_id',
        'amount',
        'net_amount',
        'tax_amount',
        'receipt_type',
        'receipt_number',
        'supplier_kra_pin',
        'supplier_name',
        'description',
        'receipt_path',
        'duplicate_of_surrender_item_id', 'duplicate_of_payment_id', 'duplicate_override_reason', 'duplicate_overridden_by', 'duplicate_overridden_at',
        'superseded_at',
        'cost_line_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'duplicate_overridden_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequisition::class, 'requisition_id');
    }

    public function expenseCode(): BelongsTo
    {
        return $this->belongsTo(ExpenseCode::class, 'expense_code_id');
    }

    public function costLine(): BelongsTo
    {
        return $this->belongsTo(CostLine::class, 'cost_line_id');
    }
}
