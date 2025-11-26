<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_materials_data_id',
        'version_number',
        'label',
        'data',
        'created_by',
        'source_updated_at'
    ];

    protected $casts = [
        'data' => 'array',
        'source_updated_at' => 'datetime'
    ];

    public function materialsData(): BelongsTo
    {
        return $this->belongsTo(TaskMaterialsData::class, 'task_materials_data_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function budgetVersions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class, 'materials_version_id');
    }
}
