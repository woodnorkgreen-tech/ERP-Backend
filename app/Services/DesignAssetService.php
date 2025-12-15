<?php

namespace App\Services;

use App\Models\DesignAsset;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;

class DesignAssetService
{
    /**
     * Upload multiple design assets for a task
     */
    public function uploadAssets(array $files, EnquiryTask $task, array $options = []): Collection
    {
        $assets = collect();

        \DB::transaction(function () use ($files, $task, $options, &$assets) {
            foreach ($files as $file) {
                $asset = $this->createAssetFromFile($file, $task, $options);
                $assets->push($asset);
            }
        });

        return $assets;
    }

    /**
     * Update asset status with approval tracking
     */
    public function updateAssetStatus(DesignAsset $asset, string $status, ?int $approvedBy = null): bool
    {
        $updateData = ['status' => $status];

        if (in_array($status, ['approved', 'rejected'])) {
            $updateData['approved_by'] = $approvedBy;
            $updateData['approved_at'] = now();
        }

        return $asset->update($updateData);
    }

    /**
     * Create asset version from existing asset
     */
    public function createAssetVersion(DesignAsset $parentAsset, $file, array $options = []): DesignAsset
    {
        $version = $parentAsset->childAssets()->max('version') + 1 ?? 2;

        return $this->createAssetFromFile($file, $parentAsset->enquiryTask, array_merge($options, [
            'parent_asset_id' => $parentAsset->id,
            'version' => $version,
            'category' => $parentAsset->category,
        ]));
    }

    /**
     * Search and filter assets with pagination
     */
    public function searchAssets(EnquiryTask $task, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = DesignAsset::byTask($task->id)->with(['uploader:id,name', 'approver:id,name']);

        if (isset($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (isset($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        if (isset($filters['search'])) {
            $query->search($filters['search']);
        }

        if (isset($filters['uploaded_by'])) {
            $query->where('uploaded_by', $filters['uploaded_by']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Get asset statistics for a task
     */
    public function getAssetStats(EnquiryTask $task): array
    {
        $assets = DesignAsset::byTask($task->id)->get();

        return [
            'total' => $assets->count(),
            'approved' => $assets->where('status', 'approved')->count(),
            'pending' => $assets->where('status', 'pending')->count(),
            'rejected' => $assets->where('status', 'rejected')->count(),
            'revision' => $assets->where('status', 'revision')->count(),
            'archived' => $assets->where('status', 'archived')->count(),
            'total_size' => $assets->sum('file_size'),
            'categories' => $assets->groupBy('category')->map->count(),
            'recent_uploads' => $assets->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Bulk update asset statuses
     */
    public function bulkUpdateStatus(array $assetIds, string $status, ?int $approvedBy = null): int
    {
        $assets = DesignAsset::whereIn('id', $assetIds)->get();
        $updated = 0;

        foreach ($assets as $asset) {
            if ($this->updateAssetStatus($asset, $status, $approvedBy)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Clean up orphaned files (files without database records)
     */
    public function cleanupOrphanedFiles(): array
    {
        $storagePath = 'design-assets';
        $allFiles = Storage::disk('public')->allFiles($storagePath);
        $databaseFiles = DesignAsset::pluck('file_path')->toArray();

        $orphanedFiles = array_diff($allFiles, $databaseFiles);
        $deletedCount = 0;

        foreach ($orphanedFiles as $file) {
            if (Storage::disk('public')->delete($file)) {
                $deletedCount++;
            }
        }

        return [
            'scanned' => count($allFiles),
            'orphaned' => count($orphanedFiles),
            'deleted' => $deletedCount,
        ];
    }

    /**
     * Validate file before upload
     */
    public function validateFile(UploadedFile $file): array
    {
        $errors = [];
        $warnings = [];

        // Size validation
        $maxSize = 50 * 1024 * 1024; // 50MB
        if ($file->getSize() > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size of 50MB';
        }

        // Type validation
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'application/postscript',
            'image/vnd.adobe.photoshop', 'application/illustrator'
        ];

        $allowedExtensions = ['sketch', 'fig', 'xd', 'ai', 'psd'];

        $isValidType = in_array($file->getMimeType(), $allowedMimes) ||
                      in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions);

        if (!$isValidType) {
            $errors[] = 'File type not supported';
        }

        // Filename validation
        if (strlen($file->getClientOriginalName()) > 255) {
            $errors[] = 'Filename is too long';
        }

        // Large file warning
        if ($file->getSize() > 10 * 1024 * 1024) {
            $warnings[] = 'Large file detected - upload may take longer';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Create asset from uploaded file
     */
    private function createAssetFromFile($file, EnquiryTask $task, array $options = []): DesignAsset
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = "design-assets/{$task->id}/{$filename}";

        $storedPath = $file->storeAs("design-assets/{$task->id}", $filename, 'public');

        return DesignAsset::create(array_merge([
            'enquiry_task_id' => $task->id,
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $options['category'] ?? $this->detectCategory($file->getClientOriginalName()),
            'status' => $options['status'] ?? 'pending',
            'description' => $options['description'] ?? null,
            'tags' => $options['tags'] ?? [],
            'version' => $options['version'] ?? 1,
            'parent_asset_id' => $options['parent_asset_id'] ?? null,
            'metadata' => $this->extractFileMetadata($file),
            'uploaded_by' => auth()->id(),
        ], $options));
    }

    /**
     * Extract metadata from file
     */
   /**
 * Extract metadata from file
 */
private function extractFileMetadata($file): array
{
    $metadata = [
        'extension' => $file->getClientOriginalExtension(),
        // Remove this line - getEncoding() doesn't exist on UploadedFile
        // 'encoding' => $file->getEncoding(),
    ];

    // Extract image metadata if applicable
    if (str_starts_with($file->getMimeType(), 'image/')) {
        try {
            $imageInfo = getimagesize($file->getRealPath());
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['bits'] = $imageInfo['bits'] ?? null;
            }
        } catch (\Exception $e) {
            // Ignore metadata extraction errors
        }
    }

    return $metadata;
}
    /**
     * Auto-detect category based on filename
     */
    private function detectCategory(string $filename): string
    {
        $name = strtolower($filename);

        if (str_contains($name, 'concept')) return 'concept';
        if (str_contains($name, 'mockup') || str_contains($name, 'mock-up')) return 'mockup';
        if (str_contains($name, 'logo')) return 'logo';
        if (str_contains($name, 'ui') || str_contains($name, 'ux')) return 'ui-ux';
        if (str_contains($name, 'illustration')) return 'illustration';
        if (str_contains($name, 'prototype')) return 'prototype';
        if (str_contains($name, 'presentation')) return 'presentation';

        // Check file extension for design files
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['ai', 'psd', 'sketch', 'fig', 'xd'])) {
            return 'artwork';
        }

        return 'other';
    }
}
