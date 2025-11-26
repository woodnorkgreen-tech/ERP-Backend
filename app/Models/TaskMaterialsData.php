<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @OA\Schema(
 *     schema="MaterialsData",
 *     title="Materials Data",
 *     description="Materials data for a task including project information and elements",
 *     @OA\Property(property="projectInfo", type="object", description="Project information"),
 *     @OA\Property(
 *         property="projectElements",
 *         type="array",
 *         @OA\Items(ref="#/components/schemas/ProjectElement")
 *     ),
 *     @OA\Property(
 *         property="availableElements",
 *         type="array",
 *         @OA\Items(ref="#/components/schemas/ElementTemplate")
 *     )
 * )
 */
class TaskMaterialsData extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        'project_info'
    ];

    protected $casts = [
        'project_info' => 'array'
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'enquiry_task_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(ProjectElement::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MaterialVersion::class)->orderBy('version_number', 'desc');
    }
}
