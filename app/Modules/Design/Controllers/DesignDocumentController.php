<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignDocument;
use App\Modules\Design\Requests\StoreDesignDocumentRequest;
use App\Modules\Design\Resources\DesignDocumentResource;
use Illuminate\Http\JsonResponse;
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

    public function download(DesignDocument $document)
    {
        abort_unless(Storage::disk('public')->exists($document->file_path), 404, 'File not found');

        return Storage::disk('public')->download($document->file_path, $document->original_name);
    }

    public function destroy(DesignDocument $document): JsonResponse
    {
        $document->update(['status' => 'archived']);

        return response()->json(['message' => 'Design document archived successfully']);
    }
}
