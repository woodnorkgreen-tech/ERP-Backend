<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

class InventoryLog extends Model
{
    protected $fillable = [
        'material_id',
        'user_id',
        'type',
        'batch_number',
        'quantity',
        'balance_after',
        'project_id',
        'supplier_id',
        'reference_no',
        'notes'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'balance_after' => 'decimal:2'
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
}
