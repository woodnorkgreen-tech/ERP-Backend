<?php

namespace App\Http\Controllers;

use App\Models\DesignAsset;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DesignAssetController extends Controller
{
    /**
     * Display a listing of design assets for a task.
     */
    public function index(Request $request, EnquiryTask $task)
    {
        // TODO: Add authorization policy when implemented
        // $this->authorize('view', $task);

        $query = DesignAsset::byTask($task->id)
            ->with(['uploader:id,name', 'approver:id,name']);

        // Apply filters
        if ($request->has('status') && $request->status) {
            $query->byStatus($request->status);
        }

        if ($request->has('category') && $request->category) {
            $query->byCategory($request->category);
        }

        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        $assets = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($assets);
    }

    /**
     * Store newly uploaded design assets.
     */
    public function store(Request $request, EnquiryTask $task)
    {
        // TODO: Add authorization policy when implemented
        // $this->authorize('update', $task);

        $request->validate([
            'files' => 'required|array|max:10',
            'files.*' => 'required|file|max:51200|mimes:jpeg,png,gif,webp,pdf,ai,psd,sketch,fig,xd',
            'category' => 'sometimes|string|in:concept,mockup,artwork,logo,ui-ux,illustration,prototype,presentation,other',
            'description' => 'sometimes|string|max:1000',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50'
        ]);

        $assets = [];
        foreach ($request->file('files') as $file) {
            $asset = $this->processFileUpload($file, $task, $request);
            $assets[] = $asset;
        }

        return response()->json($assets, 201);
    }

    /**
     * Display the specified design asset.
     */
    public function show(DesignAsset $asset)
    {
        // TODO: Add authorization policy when implemented
        // $this->authorize('view', $asset);

        return response()->json($asset->load(['uploader', 'approver', 'childAssets']));
    }

    /**
     * Update the specified design asset.
     */
    public function update(Request $request, DesignAsset $asset)
    {
        // TODO: Add authorization policy when implemented
        // $this->authorize('update', $asset);

        $request->validate([
            'category' => 'sometimes|string|in:concept,mockup,artwork,logo,ui-ux,illustration,prototype,presentation,other',
            'status' => 'sometimes|string|in:pending,approved,rejected,revision,archived',
            'description' => 'sometimes|string|max:1000',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50'
        ]);

        $asset->update($request->only(['category', 'status', 'description', 'tags']));

        return response()->json($asset);
    }

    /**
     * Remove the specified design asset.
     */
    public function destroy(DesignAsset $asset)
    {
        // TODO: Add authorization policy when implemented
        // $this->authorize('delete', $asset);

        // Delete file from storage
        Storage::disk('public')->delete($asset->file_path);

        $asset->delete();

        return response()->noContent();
    }

    /**
     * Download the specified design asset.
     */
    public function download(DesignAsset $asset)
    {
        // TODO: Add authorization policy when implemented
        // $this->authorize('view', $asset);

        if (!Storage::disk('public')->exists($asset->file_path)) {
            abort(404, 'File not found');
        }

        return Storage::disk('public')->download($asset->file_path, $asset->original_name);
    }

    /**
     * Process file upload and create asset record.
     */
    private function processFileUpload($file, EnquiryTask $task, Request $request): DesignAsset
    {
        // Generate unique filename
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = "design-assets/{$task->id}/{$filename}";

        // Store file
        $storedPath = $file->storeAs("design-assets/{$task->id}", $filename, 'public');

        // Extract metadata
        $metadata = $this->extractFileMetadata($file);

        // Auto-detect category if not provided
        $category = $request->category ?? $this->detectCategory($file->getClientOriginalName());

        return DesignAsset::create([
            'enquiry_task_id' => $task->id,
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $category,
            'status' => 'pending',
            'description' => $request->description,
            'tags' => $request->tags ?? [],
            'metadata' => $metadata,
            'uploaded_by' => auth()->id(),
        ]);
    }

    /**
     * Extract metadata from uploaded file.
     */
    private function extractFileMetadata($file): array
    {
        $metadata = [
            'extension' => $file->getClientOriginalExtension(),
            // Note: getEncoding() method doesn't exist on UploadedFile, removed
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
     * Auto-detect category based on filename.
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

    /**
     * Approve a design asset.
     */
    public function approve($task, $asset)
    {
        // Manually resolve the DesignAsset model
        $designAsset = DesignAsset::findOrFail($asset);
        
        // TODO: Add authorization policy when implemented
        // $this->authorize('approve', $designAsset);

        $designAsset->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Asset approved successfully',
            'asset' => $designAsset->load(['uploader', 'approver'])
        ]);
    }

    /**
     * Reject a design asset.
     */
    public function reject(Request $request, $task, $asset)
    {
        // Manually resolve the DesignAsset model
        $designAsset = DesignAsset::findOrFail($asset);
        
        // TODO: Add authorization policy when implemented
        // $this->authorize('approve', $designAsset);

        $request->validate([
            'reason' => 'nullable|string|max:1000'
        ]);

        $designAsset->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'message' => 'Asset rejected successfully',
            'asset' => $designAsset->load(['uploader', 'approver'])
        ]);
    }
}
