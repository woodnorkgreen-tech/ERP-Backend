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
        'consumable_unit_id', 'movement_value',
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
        'reversal_of_log_id',
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
        'logged_at',
        'movement_reason',
        'cost_centre_id',
    ];

    /** The Finance posting of stock taken in without a purchase. */
    public const POSTING_EXCEPTIONAL_RECEIPT = 'exceptional_receipt';

    /*
     * Report 76A Part 2 — why stock moved, when it was neither a project issue
     * nor a purchase receipt. One vocabulary for Stores, Finance and the API.
     */
    public const REASON_INTERNAL_CONSUMPTION = 'internal_consumption';
    public const REASON_DAMAGE = 'damage';
    public const REASON_WASTE = 'waste';
    public const REASON_OPENING_BALANCE = 'opening_balance';
    public const REASON_DISCOVERED_STOCK = 'discovered_stock';
    public const REASON_STOCK_CORRECTION = 'stock_correction';

    /** The only reasons stock may be received without a goods receipt note. */
    public const EXCEPTIONAL_RECEIPT_REASONS = [
        self::REASON_OPENING_BALANCE, self::REASON_DISCOVERED_STOCK, self::REASON_STOCK_CORRECTION,
    ];

    /**
     * The Finance posting a non-project reduction owes, by reason. Each names a
     * governed account in Finance Setup; none has a default.
     */
    public const NON_PROJECT_POSTINGS = [
        self::REASON_INTERNAL_CONSUMPTION => 'internal_consumption',
        self::REASON_DAMAGE => 'damage_loss',
        self::REASON_WASTE => 'waste_loss',
    ];

    /** Stock that left Stores for a reason other than a project. */
    public function isNonProjectReduction(): bool
    {
        return $this->nonProjectPostingType() !== null;
    }

    public function nonProjectPostingType(): ?string
    {
        if ($this->type === 'defective') {
            // A write-off recorded before reasons existed is damage.
            return self::NON_PROJECT_POSTINGS[$this->movement_reason] ?? self::NON_PROJECT_POSTINGS[self::REASON_DAMAGE];
        }
        if (in_array($this->type, ['check_out', 'issue', 'consumption'], true)
            && $this->movement_reason === self::REASON_INTERNAL_CONSUMPTION) {
            return self::NON_PROJECT_POSTINGS[self::REASON_INTERNAL_CONSUMPTION];
        }

        return null;
    }

    /**
     * Damage, waste and company use each owe the books an entry — Dr the
     * approved account / Cr Inventory — and never a project cost.
     *
     * Report 76A-3: posted in the SAME transaction as the movement. It used to
     * be queued after commit, so with no approved account the stock left the
     * shelf and the ledger kept it. Now the movement and its entry land
     * together, or the movement is refused naming what is missing — an
     * unapproved account, a shut period. Hooked on the model because several
     * writers create these rows (the stock poster, the roll service, the board
     * scrap action) and none may be the one that forgets.
     */
    // (hooked in booted(), below)

    protected $casts = [
        'quantity' => 'decimal:6',
        'entered_quantity' => 'decimal:6',
        'uom_conversion_factor' => 'decimal:6',
        'receipt_unit_cost' => 'decimal:4',
        'balance_after' => 'decimal:6',
        'logged_at' => 'datetime',
        'expiry_date' => 'date',
        'finance_synced_at' => 'datetime',
        'unit_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::created(function (self $log): void {
            if ($type = $log->nonProjectPostingType()) {
                app(\App\Modules\ProcurementStores\Services\StoresFinanceOutbox::class)->postWithMovement($log, $type);
            }
        });
        static::updating(function (self $log) {
            if ($log->consumable_unit_id && $log->isDirty(['quantity', 'material_id', 'consumable_unit_id', 'type', 'movement_value', 'receipt_unit_cost', 'project_id', 'original_issue_log_id'])) {
                throw new \DomainException('Controlled-unit movement facts are immutable. Post a correction.');
            }
        });
        static::deleting(function (self $log) {
            if ($log->consumable_unit_id || $log->getOriginal('material_id') && \App\Modules\ProcurementStores\Models\ConsumableUnitMovement::where('inventory_log_id', $log->id)->exists()) throw new \DomainException('Controlled-unit movement history cannot be deleted.');
        });
    }

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

    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_log_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_log_id');
    }

    public function projectMaterial(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ElementMaterial::class, 'project_material_id')->withTrashed();
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
