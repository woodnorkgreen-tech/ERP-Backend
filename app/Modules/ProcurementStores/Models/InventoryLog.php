<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;

class InventoryLog extends Model
{

    public function getGovernanceGate(): string
    {
        return 'financial';
    }
    protected $fillable = [
        'material_id',
        'user_id',
        'type',
        'batch_number',
        'lot_number',
        'expiry_date',
        'inventory_lot_id',
        'inventory_serial_item_id',
        'quantity',
        'entered_quantity',
        'entered_uom_id',
        'uom_conversion_factor',
        'receipt_unit_cost',
        'balance_after',
        'project_id',
        'project_material_id',
        'original_issue_log_id',
        'return_kind',
        'supplier_id',
        'reference_no',
        'unit_price',
        'recipient_name',
        'notes',
        'finance_sync_status',
        'finance_sync_attempts',
        'finance_sync_error',
        'finance_synced_at',
        'usage_type',
        'logged_at'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'entered_quantity' => 'decimal:6',
        'uom_conversion_factor' => 'decimal:6',
        'receipt_unit_cost' => 'decimal:4',
        'balance_after' => 'decimal:2',
        'logged_at' => 'datetime',
        'expiry_date' => 'date',
        'finance_synced_at' => 'datetime',
        'unit_price' => 'decimal:2',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enteredUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'entered_uom_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function allocations()
    {
        return $this->hasMany(InventoryMovementAllocation::class);
    }

    public function originalIssue(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_issue_log_id');
    }

    public function projectMaterial(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ElementMaterial::class, 'project_material_id');
    }

    public function financePosting()
    {
        return $this->hasOne(StoresFinancePosting::class);
    }

    public function returns()
    {
        return $this->hasMany(self::class, 'original_issue_log_id');
    }

    /**
     * Returns of the originally issued item reopen an approved requirement.
     * Recovered offcuts reduce project cost but do not mean the project needs
     * another full board. The notes fallback keeps pre-migration offcuts correct.
     */
    public function scopeFulfilmentReopeningReturns($query)
    {
        return $query->where('type', 'return')
            ->where(function ($scope) {
                $scope->whereNull('return_kind')
                    ->where(fn ($legacy) => $legacy->whereNull('notes')->orWhere('notes', 'not like', 'Offcut %'))
                    ->orWhere('return_kind', '!=', 'recovered_offcut');
            });
    }
}
