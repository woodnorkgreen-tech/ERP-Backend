<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HRActionType extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'hr_action_types';

    protected $fillable = [
        'name',
        'code',
        'description',
        'fields_schema',
        'requires_approval',
        'is_active'
    ];

    protected $casts = [
        'fields_schema' => 'array',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean'
    ];

    /**
     * Get the actions of this type.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(HRAction::class, 'action_type_id');
    }
}
