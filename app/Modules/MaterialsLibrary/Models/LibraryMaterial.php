<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Modules\MaterialsLibrary\Support\MaterialControl;


class LibraryMaterial extends Model
{
    use SoftDeletes;
    /**
     * The table associated with the model.
     */
    protected $table = 'library_materials';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'workstation_id',
        'item_type_id',
        'material_code',
        'material_name',
        'brand_manufacturer',
        'manufacturer_part_number',
        'alternative_item_name',
        'category',
        'subcategory',
        'material_category_id',
        'material_type',
        'item_status',
        'issue_disposition',
        'tracking_mode',
        'is_hazardous',
        'is_serialized',
        'is_batch_controlled',
        'is_expiry_controlled',
        'is_project_chargeable',
        'minimum_reusable_length_mm',
        'minimum_reusable_width_mm',
        'minimum_reusable_area_m2',
        'unit_of_measure',
        'base_uom_id',
        'purchase_uom_id',
        'issue_uom_id',
        'unit_cost',
        'default_unit_cost',
        'valuation_method',
        'revision_version',
        'effective_date',
        'attributes',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
        'approved_by',
        'approval_date',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'attributes' => 'array',
        'unit_cost' => 'decimal:2',
        'default_unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
        'is_hazardous' => 'boolean',
        'is_serialized' => 'boolean',
        'is_batch_controlled' => 'boolean',
        'is_expiry_controlled' => 'boolean',
        'is_project_chargeable' => 'boolean',
        'minimum_reusable_length_mm' => 'decimal:2',
        'minimum_reusable_width_mm' => 'decimal:2',
        'minimum_reusable_area_m2' => 'decimal:4',
        'effective_date' => 'date',
        'approval_date' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Board classification is a server-owned rule. Appending it means every
     * serialization of a material carries the authoritative answer, so clients
     * never have to re-derive it from tracking_mode and disposition and drift
     * out of step with isBoardTrackable().
     *
     * Eager-load materialCategory.parent alongside this wherever a collection
     * of materials is serialized — the category fallback path would otherwise
     * lazy-load once per row.
     */
    protected $appends = ['board_trackable', 'stock_handling', 'handling_label'];

    public function getBoardTrackableAttribute(): bool
    {
        return $this->isBoardTrackable();
    }

    /**
     * How Stores physically handles this item.
     *
     * This used to be computed independently in LibraryMaterialResource and in
     * ProcurementStoresController::inventory — and the two disagreed: one keyed
     * off issue_disposition, the other off the legacy material_type projection,
     * which reads 'reusable' for both returnable and recoverable_remainder. The
     * same item could therefore be a "reusable item" in Stores and a plain
     * quantity in the library. One definition, appended, ends that.
     */
    public function getStockHandlingAttribute(): string
    {
        if ($this->isBoardTrackable()) {
            return 'individual_board';
        }

        return match ($this->effectiveDisposition()) {
            'returnable' => 'reusable_item',
            'recoverable_remainder' => 'recoverable_item',
            default => 'quantity',
        };
    }

    /**
     * The same answer in the words the registration form uses, so the library
     * table, the Stores inventory and the form never describe one item three
     * different ways.
     */
    public function getHandlingLabelAttribute(): string
    {
        return match ($this->stock_handling) {
            'individual_board' => 'Board — tracked individually',
            'reusable_item' => 'Comes back',
            'recoverable_item' => 'Offcut is kept',
            default => 'Used up',
        };
    }

    /**
     * Disposition with the legacy fallback applied, so rows predating the
     * control columns still classify the way the rest of the system reads them.
     */
    public function effectiveDisposition(): string
    {
        return $this->issue_disposition
            ?: ($this->material_type === 'reusable' ? 'returnable' : 'consumed');
    }

    /**
     * Get the workstation that owns the material.
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(MaterialItemType::class, 'item_type_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'purchase_uom_id');
    }

    public function issueUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'issue_uom_id');
    }

    public function compatibleWorkstations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Workstation::class, 'material_workstations', 'material_id', 'workstation_id')
            ->withPivot('is_primary')->withTimestamps();
    }

    public function uomConversions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaterialUomConversion::class, 'material_id');
    }

    /**
     * The child category this material belongs to (e.g. "MDF Boards").
     * Parent eligibility (Boards / Sheet Materials / Veneer) is via materialCategory.parent.
     */
    public function materialCategory(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    /**
     * Get the user who created this material.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this material.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active materials.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query by workstation.
     */
    public function scopeByWorkstation($query, $workstationId)
    {
        return $query->where('workstation_id', $workstationId);
    }

    /**
     * Scope a query by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Query JSON attributes (works with MySQL 5.7+, PostgreSQL, SQLite 3.38+)
     * Example: Material::whereAttribute('thickness', '18mm')->get()
     */
    public function scopeWhereAttribute($query, string $key, $value)
    {
        return $query->where("attributes->{$key}", $value);
    }

    /**
     * Search materials by name or code.
     */
    public function scopeSearch($query, ?string $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('material_name', 'like', "%{$searchTerm}%")
              ->orWhere('material_code', 'like', "%{$searchTerm}%")
              ->orWhere('category', 'like', "%{$searchTerm}%")
              ->orWhere('subcategory', 'like', "%{$searchTerm}%");
        });
    }

    // Removed custom getAttribute to avoid potential conflicts with Eloquent accessor logic

    // Removed incompatible methods to use default Eloquent behavior

    /**
     * Link to the Stock level managed in Procurement & Stores
     */
    public function stock(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Modules\ProcurementStores\Models\Stock::class, 'material_id');
    }

    public function inventoryLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\ProcurementStores\Models\InventoryLog::class, 'material_id');
    }

    public function projectSpecifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ElementMaterial::class, 'library_material_id');
    }

    /**
     * The SQL form of isBoardTrackable(), with the same precedence.
     *
     * MaterialController filtered on `material_type = reusable` AND an eligible
     * category name, which is only the *fallback* half of the rule. An item
     * configured through the form as a measured, recoverable piece is board
     * tracked whatever its category is called — so the list and the behaviour
     * would have disagreed the first time someone created a board outside the
     * three seeded category names.
     */
    public function scopeBoardTrackable($query, bool $trackable = true)
    {
        $eligible = config('boards.tracking_categories', ['Boards', 'Sheet Materials', 'Veneer']);

        $matches = function ($scope) use ($eligible) {
            $scope->where(function ($governed) {
                $governed->whereNotNull('tracking_mode')
                    ->where('tracking_mode', 'dimension_piece')
                    ->where('issue_disposition', 'recoverable_remainder');
            })->orWhere(function ($legacy) use ($eligible) {
                // Only rows with no tracking_mode fall back to category names.
                $legacy->whereNull('tracking_mode')
                    ->where('material_type', 'reusable')
                    ->where(function ($category) use ($eligible) {
                        $category->whereIn('category', $eligible)
                            ->orWhereHas('materialCategory', function ($node) use ($eligible) {
                                $node->whereIn('name', $eligible)
                                    ->orWhereHas('parent', fn ($parent) => $parent->whereIn('name', $eligible));
                            });
                    });
            });
        };

        return $trackable
            ? $query->where($matches)
            : $query->whereNot($matches);
    }

    /**
     * Whether this catalogue item must use the individual board lifecycle.
     *
     * `material_type = reusable` only describes whether an item is expected back;
     * it also applies to tools and equipment. Board tracking is narrower: the item
     * must be reusable AND belong to an approved board/sheet category.
     */
    public function isBoardTrackable(): bool
    {
        if ($this->tracking_mode) {
            return $this->tracking_mode === 'dimension_piece'
                && $this->issue_disposition === 'recoverable_remainder';
        }

        if ($this->material_type !== 'reusable') {
            return false;
        }

        $this->loadMissing('materialCategory.parent');

        $rootCategory = $this->materialCategory?->parent?->name
            ?? $this->materialCategory?->name
            ?? $this->category
            ?? '';

        return in_array(
            $rootCategory,
            config('boards.tracking_categories', ['Boards', 'Sheet Materials', 'Veneer']),
            true
        );
    }

    public function expectedUsageType(): string
    {
        return MaterialControl::legacyUsageType($this->effectiveDisposition());
    }
}
