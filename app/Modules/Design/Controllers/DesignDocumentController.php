<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignDocument;
use App\Modules\Design\Requests\StoreDesignDocumentRequest;
use App\Modules\Design\Resources\DesignDocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DesignDocumentController extends Controller
{
    public function store(StoreDesignDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('design/documents', $filename, 'public');

        $document = DesignDocument::create([
            'design_job_id' => $request->input('design_job_id'),
            'design_item_id' => $request->input('design_item_id'),
            'document_type' => $request->input('document_type', 'other'),
            'name' => $request->input('name') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'source' => 'file',
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Design document uploaded successfully',
            'data' => new DesignDocumentResource($document),
        ], 201);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'design_job_id' => 'nullable|integer|exists:design_jobs,id|required_without:design_item_id',
            'design_item_id' => 'nullable|integer|exists:design_items,id|required_without:design_job_id',
            'document_type' => 'nullable|string|max:80',
            'name' => 'nullable|string|max:255',
            'url' => 'required|url|max:2048',
        ]);

        $name = $validated['name'] ?? (parse_url($validated['url'], PHP_URL_HOST) ?: $validated['url']);

        $document = DesignDocument::create([
            'design_job_id' => $validated['design_job_id'] ?? null,
            'design_item_id' => $validated['design_item_id'] ?? null,
            'document_type' => $validated['document_type'] ?? 'reference',
            'name' => $name,
            'original_name' => $name,
            'source' => 'link',
            'external_url' => $validated['url'],
            'file_path' => $validated['url'],
            'file_size' => 0,
            'mime_type' => 'text/uri-list',
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Design link attached successfully',
            'data' => new DesignDocumentResource($document),
        ], 201);
    }

    public function download(DesignDocument $document)
    {
        if ($document->isLink()) {
            return redirect()->away($document->external_url ?: $document->file_path);
        }

        abort_unless(Storage::disk('public')->exists($document->file_path), 404, 'File not found');

        return Storage::disk('public')->download($document->file_path, $document->original_name);
    }

    public function destroy(DesignDocument $document): JsonResponse
    {
        $document->update(['status' => 'archived']);

        return response()->json(['message' => 'Design document archived successfully']);
    }
}
