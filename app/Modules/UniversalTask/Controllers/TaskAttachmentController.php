<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAttachment;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAttachmentController
{
    protected TaskPermissionService $permissionService;

    public function __construct(TaskPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of attachments for a task.
     */
    public function index(Request $request): JsonResponse
    {
        $taskId = $request->route('task');
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TASK_NOT_FOUND',
                    'message' => 'The specified task does not exist.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view attachments for this task.',
                ]
            ], 403);
        }

        try {
            $attachments = $task->attachments()
                ->with('uploader')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $attachments,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving attachments.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly uploaded attachment.
     */
    public function store(Request $request): JsonResponse
    {
        $taskId = $request->route('task');
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TASK_NOT_FOUND',
                    'message' => 'The specified task does not exist.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check edit permission for the task (required to upload attachments)
        if (!$this->permissionService->canEdit($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to upload attachments to this task.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:51200', // 50MB max
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid attachment data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        $file = $request->file('file');

        try {
            // Generate unique filename to avoid conflicts
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $filename = pathinfo($originalName, PATHINFO_FILENAME);
            $uniqueName = $filename . '_' . time() . '_' . uniqid() . '.' . $extension;

            // Store file
            $path = $file->storeAs('task-attachments', $uniqueName, 'public');

            // Create attachment record
            $attachment = TaskAttachment::create([
                'task_id' => $task->id,
                'uploaded_by' => $user->id,
                'file_name' => $originalName,
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'description' => $request->description,
            ]);

            // Load uploader relationship
            $attachment->load('uploader');

            return response()->json([
                'success' => true,
                'message' => 'Attachment uploaded successfully.',
                'data' => $attachment,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPLOAD_FAILED',
                    'message' => 'Failed to upload attachment: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified attachment.
     */
    public function show(Request $request, TaskAttachment $attachment): JsonResponse
    {
        $taskId = $request->route('task');
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TASK_NOT_FOUND',
                    'message' => 'The specified task does not exist.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Ensure attachment belongs to the task
        if ($attachment->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Attachment not found for this task.',
                ]
            ], 404);
        }

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this attachment.',
                ]
            ], 403);
        }

        try {
            $attachment->load(['uploader', 'task']);

            return response()->json([
                'success' => true,
                'data' => $attachment,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the attachment.',
                ]
            ], 500);
        }
    }

    /**
     * Download the specified attachment.
     */
    public function download(Request $request, TaskAttachment $attachment): StreamedResponse
    {
        $taskId = $request->route('task');
        $task = Task::find($taskId);

        if (!$task) {
            abort(404, 'Task not found.');
        }

        $user = Auth::user();

        // Ensure attachment belongs to the task
        if ($attachment->task_id !== $task->id) {
            abort(404, 'Attachment not found for this task.');
        }

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            abort(403, 'You do not have permission to download this attachment.');
        }

        // Check if file exists
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download(
            $attachment->file_path,
            $attachment->file_name
        );
    }

    /**
     * Remove the specified attachment.
     */
    public function destroy(Request $request, TaskAttachment $attachment): JsonResponse
    {
        $taskId = $request->route('task');
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TASK_NOT_FOUND',
                    'message' => 'The specified task does not exist.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Ensure attachment belongs to the task
        if ($attachment->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Attachment not found for this task.',
                ]
            ], 404);
        }

        // Check edit permission for the task (required to delete attachments)
        if (!$this->permissionService->canEdit($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to delete attachments from this task.',
                ]
            ], 403);
        }

        try {
            // Delete file from storage
            if (Storage::disk('public')->exists($attachment->file_path)) {
                Storage::disk('public')->delete($attachment->file_path);
            }

            // Delete record
            $attachment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Attachment deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete attachment: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Get all versions of an attachment.
     */
    public function getVersions(Request $request, string $filename): JsonResponse
    {
        $taskId = $request->route('task');
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TASK_NOT_FOUND',
                    'message' => 'The specified task does not exist.',
                ]
            ], 404);
        }

        $user = Auth::user();

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view attachment versions for this task.',
                ]
            ], 403);
        }

        try {
            $versions = TaskAttachment::where('task_id', $task->id)
                ->where('file_name', $filename)
                ->with('uploader')
                ->orderBy('version', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $versions,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving attachment versions.',
                ]
            ], 500);
        }
    }
}