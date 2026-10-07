<?php

namespace App\Models;

use App\Constants\ScopeClassification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/**
 * @OA\Schema(
 *     schema="ProjectElement",
 *     title="Project Element",
 *     description="A project element with its materials",
 *
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
 *
 *         @OA\Items(ref="#/components/schemas/ElementMaterial")
 *     ),
 *
 *     @OA\Property(property="addedAt", type="string", format="date-time", description="When element was added")
 * )
 *
 * @OA\Schema(
 *     schema="ProjectElementInput",
 *     title="Project Element Input",
 *     description="Input data for creating/updating a project element",
 *
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
 *
 *         @OA\Items(ref="#/components/schemas/ElementMaterialInput")
 *     )
 * )
 */
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProjectElement extends Model
{
    use HasFactory, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'project_deliverables';

    const DELETED_AT = 'archived_at';

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            if (! $model->isForceDeleting()) {
                $model->materials()->delete();
            }
        });

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (! $model->enquiry_id && $model->task_materials_data_id) {
                $model->enquiry_id = TaskMaterialsData::findOrFail($model->task_materials_data_id)->task->project_enquiry_id;
            }
            $model->name = $model->name ?: ($model->element_type ?: 'Untitled');
            $model->classification = $model->classification ?: 'PRE-DEFINED';
            $model->status = $model->status ?: 'original';
            if (! $model->element_type) {
                $model->element_type = $model->classification;
            }
            if (! $model->category) {
                $model->category = ScopeClassification::toElementCategory($model->classification);
            }
            if (empty($model->persistent_id)) {
                $model->persistent_id = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'enquiry_id', 'uuid', 'classification', 'status', 'parent_id', 'archived_at',
        'task_materials_data_id',
        'template_id',
        'scope_id',
        'element_type',
        'name',
        'persistent_id',
        'category',
        'required_quantity',
        'unit_of_measurement',
        'dimensions',
        'is_included',
        'notes',
        'source_metadata',
        'sort_order',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'is_included' => 'boolean',
        'required_quantity' => 'decimal:4',
        'sort_order' => 'integer',
        'source_metadata' => 'array',
    ];

    public function getScopeIdAttribute(): string
    {
        return $this->uuid;
    }

    public function setScopeIdAttribute($value): void
    {
        if ($value) {
            $this->attributes['uuid'] = $value;
        }
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'enquiry_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function taskMaterialsData(): BelongsTo
    {
        return $this->belongsTo(TaskMaterialsData::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ElementMaterial::class)->orderBy('sort_order');
    }
}
