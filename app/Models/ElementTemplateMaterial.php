<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="ElementTemplateMaterial",
 *     title="Element Template Material",
 *     description="Default material for an element template",
 *     @OA\Property(property="id", type="integer", description="Material ID"),
 *     @OA\Property(property="description", type="string", description="Material description"),
 *     @OA\Property(property="unitOfMeasurement", type="string", description="Unit of measurement"),
 *     @OA\Property(property="defaultQuantity", type="number", format="float", description="Default quantity"),
 *     @OA\Property(property="isDefaultIncluded", type="boolean", description="Whether material is included by default"),
 *     @OA\Property(property="order", type="integer", description="Sort order")
 * )
 *
 * @OA\Schema(
 *     schema="ElementTemplateMaterialInput",
 *     title="Element Template Material Input",
 *     description="Input data for creating/updating a template material",
 *     @OA\Property(property="description", type="string", description="Material description"),
 *     @OA\Property(property="unitOfMeasurement", type="string", description="Unit of measurement"),
 *     @OA\Property(property="defaultQuantity", type="number", format="float", description="Default quantity"),
 *     @OA\Property(property="isDefaultIncluded", type="boolean", description="Whether material is included by default"),
 *     @OA\Property(property="order", type="integer", description="Sort order")
 * )
 */
class ElementTemplateMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'element_template_id',
        'description',
        'unit_of_measurement',
        'default_quantity',
        'is_default_included',
        'sort_order'
    ];

    protected $casts = [
        'default_quantity' => 'decimal:2',
        'is_default_included' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ElementTemplate::class, 'element_template_id');
    }
}
