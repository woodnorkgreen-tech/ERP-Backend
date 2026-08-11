<?php

namespace App\Modules\MaterialsLibrary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialItemType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'default_issue_disposition',
        'default_tracking_mode', 'is_stock_item', 'is_active',
    ];

    protected $casts = ['is_stock_item' => 'boolean', 'is_active' => 'boolean'];

    public function materials(): HasMany
    {
        return $this->hasMany(LibraryMaterial::class, 'item_type_id');
    }
}
