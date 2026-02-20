<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionDefectCode extends Model
{
    use HasFactory;

    protected $table = 'production_defect_codes';

    protected $fillable = [
        'code',
        'name',
        'defect_group',
        'default_severity',
        'default_stage',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function ncrs(): HasMany
    {
        return $this->hasMany(ProductionNcr::class, 'defect_code_id');
    }
}
