<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report 78C — an outstanding BOM quantity recorded as fulfilled outside the
 * ERP. It is a statement about demand, never a stock movement: nothing is
 * issued, received or posted. Revoking stamps the row; it is never deleted.
 *
 * @see \App\Modules\ProcurementStores\Services\MaterialRequirementRetirements
 */
class MaterialRequirementRetirement extends Model
{
    public const BASIS_LEGACY = 'legacy_fulfilled_outside_erp';

    protected $fillable = [
        'element_material_id', 'project_enquiry_id', 'retired_bom_quantity', 'bom_quantity_snapshot',
        'basis', 'reason', 'retired_by', 'retired_at',
        'revoked_by', 'revoked_at', 'revoke_reason', 'reconciliation_note',
    ];

    protected $casts = [
        'retired_bom_quantity' => 'decimal:6',
        'bom_quantity_snapshot' => 'decimal:6',
        'retired_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ElementMaterial::class, 'element_material_id')->withTrashed();
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by')->select('id', 'name')->without('roles');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by')->select('id', 'name')->without('roles');
    }
}
