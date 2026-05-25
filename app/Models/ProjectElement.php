<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @OA\Schema(
 *     schema="ProjectElement",
 *     title="Project Element",
 *     description="A project element with its materials",
 *     @OA\Property(property="id", type="integer", description="Element ID"),
 *     @OA\Property(property="templateId", type="integer", nullable=true, description="Template ID"),
 *     @OA\Property(property="elementType", type="string", description="Element type"),
 *     @OA\Property(property="name", type="string", description="Element name"),
 *     @OA\Property(property="category", type="string", enum={"production", "hire", "outsourced"}, description="Element category"),
 *     @OA\Property(property="dimensions", type="array", description="Element dimensions", @OA\Items(type="string")),
 *     @OA\Property(property="isIncluded", type="boolean", description="Whether element is included"),
 *     @OA\Property(property="notes", type="string", nullable=true, description="Element notes"),
 *     @OA\Property(
 *         property="materials",
 *         type="array",
 *         @OA\Items(ref="#/components/schemas/ElementMaterial")
 *     ),
 *     @OA\Property(property="addedAt", type="string", format="date-time", description="When element was added")
 * )
 *
 * @OA\Schema(
 *     schema="ProjectElementInput",
 *     title="Project Element Input",
 *     description="Input data for creating/updating a project element",
 *     @OA\Property(property="id", type="string", description="Element ID"),
 *     @OA\Property(property="templateId", type="integer", nullable=true, description="Template ID"),
 *     @OA\Property(property="elementType", type="string", description="Element type"),
 *     @OA\Property(property="name", type="string", description="Element name"),
 *     @OA\Property(property="category", type="string", enum={"production", "hire", "outsourced"}, description="Element category"),
 *     @OA\Property(property="dimensions", type="array", description="Element dimensions", @OA\Items(type="string")),
 *     @OA\Property(property="isIncluded", type="boolean", description="Whether element is included"),
 *     @OA\Property(property="notes", type="string", nullable=true, description="Element notes"),
 *     @OA\Property(
 *         property="materials",
 *         type="array",
 *         @OA\Items(ref="#/components/schemas/ElementMaterialInput")
 *     )
 * )
 */
use Illuminate\Support\Str;

class ProjectElement extends Model
{
    use HasFactory;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->persistent_id)) {
                $model->persistent_id = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'task_materials_data_id',
        'template_id',
        'scope_id',
        'element_type',
        'name',
        'persistent_id',
        'category',
        'dimensions',
        'is_included',
        'notes',
        'sort_order'
    ];

    protected $casts = [
        'dimensions' => 'array',
        'is_included' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function taskMaterialsData(): BelongsTo
    {
        return $this->belongsTo(TaskMaterialsData::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ElementMaterial::class)->orderBy('sort_order');
    }
}
