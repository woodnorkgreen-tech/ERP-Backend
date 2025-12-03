<?php

namespace App\Modules\UniversalTask\Controllers;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskComment;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use App\Modules\UniversalTask\Events\UserMentioned;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskCommentController
{
    protected TaskPermissionService $permissionService;

    public function __construct(TaskPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of comments for a task.
     */
    public function index(Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view comments for this task.',
                ]
            ], 403);
        }

        try {
            $comments = $task->comments()
                ->with(['user', 'replies.user'])
                ->whereNull('parent_comment_id') // Only top-level comments
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $comments,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving comments.',
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created comment.
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        // Check view permission for the task (required to comment)
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to comment on this task.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
            'parent_comment_id' => 'nullable|exists:task_comments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid comment data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        // If replying to a comment, ensure the parent comment belongs to the same task
        if ($request->parent_comment_id) {
            $parentComment = TaskComment::find($request->parent_comment_id);
            if ($parentComment->task_id !== $task->id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_PARENT',
                        'message' => 'Parent comment does not belong to this task.',
                    ]
                ], 400);
            }
        }

        try {
            $comment = TaskComment::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'parent_comment_id' => $request->parent_comment_id,
                'content' => $request->content,
            ]);

            // Load relationships
            $comment->load(['user', 'replies']);

            // Trigger UserMentioned event for any mentioned users
            $mentionedUsers = $comment->getMentionedUsers();
            if ($mentionedUsers->isNotEmpty()) {
                foreach ($mentionedUsers as $mentionedUser) {
                    event(new UserMentioned($comment, $mentionedUser, $user));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Comment created successfully.',
                'data' => $comment,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATION_FAILED',
                    'message' => 'Failed to create comment: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Display the specified comment.
     */
    public function show(Task $task, TaskComment $comment): JsonResponse
    {
        $user = Auth::user();

        // Ensure comment belongs to the task
        if ($comment->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Comment not found for this task.',
                ]
            ], 404);
        }

        // Check view permission for the task
        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this comment.',
                ]
            ], 403);
        }

        try {
            $comment->load(['user', 'replies.user', 'parentComment.user']);

            return response()->json([
                'success' => true,
                'data' => $comment,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while retrieving the comment.',
                ]
            ], 500);
        }
    }

    /**
     * Update the specified comment.
     */
    public function update(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        $user = Auth::user();

        // Ensure comment belongs to the task
        if ($comment->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Comment not found for this task.',
                ]
            ], 404);
        }

        // Check if user can edit this comment (only author can edit their own comments)
        if ($comment->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only edit your own comments.',
                ]
            ], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid comment data.',
                    'details' => $validator->errors(),
                ]
            ], 422);
        }

        try {
            $comment->update([
                'content' => $request->content,
            ]);

            $comment->load(['user', 'replies']);

            return response()->json([
                'success' => true,
                'message' => 'Comment updated successfully.',
                'data' => $comment,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => 'Failed to update comment: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified comment.
     */
    public function destroy(Task $task, TaskComment $comment): JsonResponse
    {
        $user = Auth::user();

        // Ensure comment belongs to the task
        if ($comment->task_id !== $task->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Comment not found for this task.',
                ]
            ], 404);
        }

        // Check if user can delete this comment (only author can delete their own comments)
        if ($comment->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You can only delete your own comments.',
                ]
            ], 403);
        }

        try {
            $comment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Comment deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETION_FAILED',
                    'message' => 'Failed to delete comment: ' . $e->getMessage(),
                ]
            ], 500);
        }
    }

    /**
     * Create a reply to a comment.
     */
    public function reply(Request $request, Task $task, TaskComment $comment): JsonResponse
    {
        // Reuse the store method but force the parent_comment_id
        $request->merge(['parent_comment_id' => $comment->id]);

        return $this->store($request, $task);
    }
}