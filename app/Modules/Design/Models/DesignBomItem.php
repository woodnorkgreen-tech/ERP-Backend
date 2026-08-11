<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignBomItem extends Model
{
    protected $fillable = [
        'design_item_id',
        'material_id',
        'description',
        'specification',
        'quantity',
        'unit',
        'wastage_percent',
        'source',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'wastage_percent' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(DesignItem::class, 'design_item_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
