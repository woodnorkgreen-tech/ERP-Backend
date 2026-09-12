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
        'cost_line_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
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
