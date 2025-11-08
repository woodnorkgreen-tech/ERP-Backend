<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @OA\Schema(
 *     schema="ElementTemplate",
 *     title="Element Template",
 *     description="A template for project elements with default materials",
 *     @OA\Property(property="id", type="string", description="Template ID (name)"),
 *     @OA\Property(property="name", type="string", description="Template name"),
 *     @OA\Property(property="displayName", type="string", description="Display name"),
 *     @OA\Property(property="description", type="string", nullable=true, description="Template description"),
 *     @OA\Property(property="category", type="string", enum={"structure", "decoration", "flooring", "technical", "furniture", "branding", "custom"}, description="Template category"),
 *     @OA\Property(property="color", type="string", description="Template color"),
 *     @OA\Property(property="order", type="integer", description="Sort order"),
 *     @OA\Property(
 *         property="defaultMaterials",
 *         type="array",
 *         @OA\Items(ref="#/components/schemas/ElementTemplateMaterial")
 *     )
 * )
 */
class ElementTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'display_name', 'description', 'category',
        'color', 'sort_order', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(ElementTemplateMaterial::class)->orderBy('sort_order');
    }
}
