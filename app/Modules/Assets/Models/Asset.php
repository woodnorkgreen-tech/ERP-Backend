<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'assets';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'asset_code',
        'import_hash',
        'name',
        'ownership_type',
        'client_name',
        'image_path',
        'category',
        'category_id',
        'subcategory',
        'manufacturer',
        'model',
        'serial_number',
        'specifications',
        'qty',
        'status',
        'is_available',
        'condition',
        'assigned_to',
        'department_id',
        'location',
        'purchase_date',
        'purchase_cost_kes',
        'purchase_cost_usd',
        'current_value',
        'supplier',
        'warranty_expiry',
        'next_service_date',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expiry'    => 'date',
        'next_service_date'  => 'date',
        'purchase_cost_kes' => 'decimal:2',
        'purchase_cost_usd' => 'decimal:2',
        'current_value' => 'decimal:2',
        'qty' => 'integer',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
    ];

    /**
     * Full URL to the asset's image, or null if it has none.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->image_path)
            : null;
    }

    /**
     * The employee currently holding/responsible for this asset.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The department this asset is allocated to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The normalised category record (legacy `category` string column is
     * kept in sync alongside this, the same dual-write pattern used in
     * MaterialsLibrary, so older display code still works).
     */
    public function assetCategory(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function serviceLogs(): HasMany
    {
        return $this->hasMany(AssetServiceLog::class)->orderByDesc('service_date');
    }

    public function hireRequests(): HasMany    {
        return $this->hasMany(AssetHireRequest::class);
    }

    /**
     * The current active hand-out, if any — what's surfacing "given out to
     * X for Y" on the asset itself, separate from the full history log.
     */
    public function activeHireRequest(): HasOne
    {
        return $this->hasOne(AssetHireRequest::class)
            ->where('status', AssetHireRequest::STATUS_APPROVED)
            ->latestOfMany();
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(AssetAssignmentHistory::class)->orderByDesc('started_at');
    }

    /**
     * The user who created this asset record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this asset record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active asset records.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query by category (legacy string column).
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query by the normalised category FK.
     */
    public function scopeByCategoryId($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope a query by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query by department.
     */
    public function scopeByDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Scope a query by ownership type (Company / Client).
     */
    public function scopeByOwnership($query, $ownershipType)
    {
        return $query->where('ownership_type', $ownershipType);
    }

    /**
     * Search assets by name, code, category, location, client name, or hardware identifiers.
     */
    public function scopeSearch($query, ?string $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('name', 'like', "%{$searchTerm}%")
              ->orWhere('asset_code', 'like', "%{$searchTerm}%")
              ->orWhere('category', 'like', "%{$searchTerm}%")
              ->orWhere('subcategory', 'like', "%{$searchTerm}%")
              ->orWhere('location', 'like', "%{$searchTerm}%")
              ->orWhere('client_name', 'like', "%{$searchTerm}%")
              ->orWhere('manufacturer', 'like', "%{$searchTerm}%")
              ->orWhere('model', 'like', "%{$searchTerm}%")
              ->orWhere('serial_number', 'like', "%{$searchTerm}%");
        });
    }

    /**
     * Generate the next tag in the WNG-{CATEGORY_CODE}-{YYMM}-{SEQ} scheme,
     * e.g. WNG-CAM-2606-0001. The sequence resets each month per category
     * code, so numbers stay short and the tag itself tells you what it is
     * and roughly when it was registered. Falls back to WNG-GEN-... when
     * no category (or no code on the category) is given.
     */
    public static function generateAssetCode(?int $categoryId = null): string
    {
        $code = 'GEN';

        if ($categoryId) {
            $category = AssetCategory::find($categoryId);
            if ($category) {
                $code = $category->code ?: AssetCategory::suggestCode($category->name);
            }
        }

        $prefix = 'WNG-' . $code . '-' . now()->format('ym') . '-';

        $last = static::withTrashed()
            ->where('asset_code', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(asset_code, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->first();

        $nextNumber = $last
            ? ((int) substr($last->asset_code, strlen($prefix))) + 1
            : 1;

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
