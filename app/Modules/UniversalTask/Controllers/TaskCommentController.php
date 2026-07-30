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

        // Debug: Log authentication status
        // TEMP disabled 2026-07-27 for perf testing - revert (uncomment) later
        // \Log::info('TaskCommentController@index - User authentication check', [
        //     'user_id' => $user ? $user->id : null,
        //     'task_id' => $task->id,
        //     'authenticated' => $user !== null
        // ]);

        // Check if user is authenticated
        if (!$user) {
            // \Log::error('TaskCommentController@index - User not authenticated');
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required to view comments.',
                ]
            ], 401);
        }

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
     * Search users that can be mentioned on a task comment.
     */
    public function mentionableUsers(Request $request): JsonResponse
    {
        $task = Task::find($request->route('task'));

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

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required to search mentionable users.',
                ]
            ], 401);
        }

        if (!$this->permissionService->canView($user, $task)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to view this task.',
                ]
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));
        $assigneeIds = $task->assignments()->pluck('user_id')
            ->merge([$task->assigned_user_id, $task->created_by])
            ->filter()
            ->unique()
            ->values();

        $query = User::query()
            ->active()
            ->select(['id', 'name', 'email', 'department_id']);

        if (!$this->permissionService->hasFullAccess($user)) {
            $query->where(function ($q) use ($task, $assigneeIds) {
                if ($task->department_id) {
                    $q->where('department_id', $task->department_id);
                }

                if ($assigneeIds->isNotEmpty()) {
                    $q->orWhereIn('id', $assigneeIds);
                }
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created comment.
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

        // Debug: Check authentication and task details
        // TEMP disabled 2026-07-27 for perf testing - revert (uncomment) later
        // \Log::info('Comment creation attempt for task ID: ' . $taskId, [
        //     'user_id' => $user ? $user->id : null,
        //     'authenticated' => $user !== null
        // ]);

        // Check if user is authenticated
        if (!$user) {
            // \Log::error('Comment creation failed - User not authenticated for task ID: ' . $taskId);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required to create comments.',
                ]
            ], 401);
        }

        // \Log::info('Task found: ' . $task->id . ' - ' . $task->title, [
        //     'user_id' => $user->id,
        //     'user_name' => $user->name
        // ]);

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
            'mentioned_user_ids' => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
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
            if (!$parentComment) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_PARENT',
                        'message' => 'Parent comment not found.',
                    ]
                ], 404);
            }
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

            // Trigger UserMentioned event for selected mentions and legacy @username text mentions.
            $mentionedIds = collect($request->input('mentioned_user_ids', []))
                ->filter()
                ->unique()
                ->values();

            $mentionedUsers = User::whereIn('id', $mentionedIds)
                ->get()
                ->merge($comment->getMentionedUsers())
                ->unique('id')
                ->reject(fn (User $mentionedUser) => $mentionedUser->id === $user->id)
                ->values();

            if ($mentionedUsers->isNotEmpty()) {
                foreach ($mentionedUsers as $mentionedUser) {
                    event(new UserMentioned($task, $mentionedUser, $user, $comment->content));
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
    public function show(Request $request, TaskComment $comment): JsonResponse
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
    public function update(Request $request, TaskComment $comment): JsonResponse
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
    public function destroy(Request $request, TaskComment $comment): JsonResponse
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
    public function reply(Request $request, string|int $task, string|int $comment): JsonResponse
    {
        $parentComment = TaskComment::find($comment);
        if (!$parentComment) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'COMMENT_NOT_FOUND',
                    'message' => 'The comment you are replying to does not exist.',
                ]
            ], 404);
        }

        if ((int) $parentComment->task_id !== (int) $task) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PARENT',
                    'message' => 'Parent comment does not belong to this task.',
                ]
            ], 400);
        }

        // Reuse the store method but force the parent_comment_id
        $request->merge(['parent_comment_id' => $parentComment->id]);

        return $this->store($request);
    }
}
