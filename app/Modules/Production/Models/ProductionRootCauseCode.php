<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionRootCauseCode extends Model
{
    use HasFactory;

    protected $table = 'production_root_cause_codes';

    protected $fillable = [
        'code',
        'name',
        'cause_group',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function ncrs(): HasMany
    {
        return $this->hasMany(ProductionNcr::class, 'root_cause_code_id');
    }
}
