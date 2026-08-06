<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

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
        'balance_after',
        'project_id',
        'supplier_id',
        'reference_no',
        'recipient_name',
        'notes',
        'usage_type',
        'logged_at'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'logged_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function allocations()
    {
        return $this->hasMany(InventoryMovementAllocation::class);
    }
}
