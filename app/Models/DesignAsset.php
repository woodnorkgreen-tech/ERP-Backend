<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Projects\Models\EnquiryTask;

class DesignAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        'name',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'category',
        'status',
        'description',
        'tags',
        'version',
        'parent_asset_id',
        'metadata',
        'uploaded_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'file_size' => 'integer',
        'version' => 'integer',
        'approved_at' => 'datetime',
    ];

    protected $appends = [
        'file_url',
    ];

    // Relationships
    public function enquiryTask(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'enquiry_task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(DesignAsset::class, 'parent_asset_id');
    }

    public function childAssets(): HasMany
    {
        return $this->hasMany(DesignAsset::class, 'parent_asset_id');
    }

    // Scopes
    public function scopeByTask($query, $taskId)
    {
        return $query->where('enquiry_task_id', $taskId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('description', 'LIKE', "%{$search}%")
              ->orWhereJsonContains('tags', $search);
        });
    }

    // Accessor for file URL
   public function getFileUrlAttribute(): string
{
    return url('storage/' . $this->file_path);
}

    // Accessor for formatted file size
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    // Check if file is an image
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    // Get file extension
    public function getFileExtension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }
}
