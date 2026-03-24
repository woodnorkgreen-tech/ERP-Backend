<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EmployeeDocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($employeeId)
    {
        $employee = Employee::findOrFail($employeeId);
        
        $documents = $employee->documents()
            ->with('uploader:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($documents);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $employeeId)
    {
        $employee = Employee::findOrFail($employeeId);

        $request->validate([
            'document' => 'required|file|max:10240', // Max 10MB
            'document_type' => ['required', Rule::in(['contract', 'id_passport', 'academic', 'professional', 'other'])],
        ]);

        $file = $request->file('document');
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        // Store file securely (e.g., local storage, private disk)
        // Storage path format: hr/employees/{employee_id}/documents/{time}_{filename}
        $path = $file->storeAs(
            "hr/employees/{$employee->id}/documents", 
            time() . '_' . $originalName,
            str_replace('public', 'local', env('FILESYSTEM_DISK', 'local')) // Ensure it's not public if possible
        );

        $document = $employee->documents()->create([
            'document_type' => $request->document_type,
            'name' => $originalName,
            'file_path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'uploaded_by' => Auth::id(),
        ]);

        return response()->json($document->load('uploader:id,name,email'), 201);
    }

    /**
     * Download the specified resource.
     */
    public function download($employeeId, $documentId)
    {
        $document = EmployeeDocument::where('employee_id', $employeeId)->findOrFail($documentId);
        
        $disk = str_replace('public', 'local', env('FILESYSTEM_DISK', 'local'));

        if (!Storage::disk($disk)->exists($document->file_path)) {
            return response()->json(['message' => 'Document file not found on server.'], 404);
        }

        return Storage::disk($disk)->download($document->file_path, $document->name);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($employeeId, $documentId)
    {
        $document = EmployeeDocument::where('employee_id', $employeeId)->findOrFail($documentId);
        
        $disk = str_replace('public', 'local', env('FILESYSTEM_DISK', 'local'));

        // Delete from storage
        if (Storage::disk($disk)->exists($document->file_path)) {
            Storage::disk($disk)->delete($document->file_path);
        }
        
        // Delete from DB
        $document->delete();

        return response()->json(['message' => 'Document purged securely.']);
    }
}
