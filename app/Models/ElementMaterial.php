<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="ElementMaterial",
 *     title="Element Material",
 *     description="A material used in a project element",
 *     @OA\Property(property="id", type="integer", description="Material ID"),
 *     @OA\Property(property="description", type="string", description="Material description"),
 *     @OA\Property(property="unitOfMeasurement", type="string", description="Unit of measurement"),
 *     @OA\Property(property="quantity", type="number", format="float", description="Material quantity"),
 *     @OA\Property(property="isIncluded", type="boolean", description="Whether material is included"),
 *     @OA\Property(property="notes", type="string", nullable=true, description="Material notes"),
 *     @OA\Property(property="createdAt", type="string", format="date-time", description="Creation timestamp"),
 *     @OA\Property(property="updatedAt", type="string", format="date-time", description="Last update timestamp")
 * )
 *
 * @OA\Schema(
 *     schema="ElementMaterialInput",
 *     title="Element Material Input",
 *     description="Input data for creating/updating an element material",
 *     @OA\Property(property="description", type="string", description="Material description"),
 *     @OA\Property(property="unitOfMeasurement", type="string", description="Unit of measurement"),
 *     @OA\Property(property="quantity", type="number", format="float", description="Material quantity"),
 *     @OA\Property(property="isIncluded", type="boolean", description="Whether material is included"),
 *     @OA\Property(property="notes", type="string", nullable=true, description="Material notes")
 * )
 */
class ElementMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_element_id',
        'description',
        'unit_of_measurement',
        'quantity',
        'is_included',
        'notes',
        'sort_order'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'is_included' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function element(): BelongsTo
    {
        return $this->belongsTo(ProjectElement::class, 'project_element_id');
    }
}
