<?php

namespace App\Http\Controllers;

use App\Modules\Projects\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Get all notifications for authenticated user
     * SECURITY: Always uses authenticated user's ID
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            $unreadOnly = $request->query('unread_only', false);
            $type = $request->query('type');
            $limit = (int) $request->query('limit', 50);

            $notifications = $this->notificationService->getUserNotifications(
                $userId,
                filter_var($unreadOnly, FILTER_VALIDATE_BOOLEAN),
                $type,
                min($limit, 100) // Cap at 100
            );

            return response()->json([
                'success' => true,
                'data' => $notifications
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch notifications',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark notification as read
     * SECURITY: Verifies ownership before marking
     */
    public function markAsRead(int $id): JsonResponse
    {
        try {
            $userId = Auth::id();
            $success = $this->notificationService->markAsRead($id, $userId);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found or access denied'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark notification as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to mark notification as read',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     * SECURITY: Only affects current user's notifications
     */
    public function markAllAsRead(): JsonResponse
    {
        try {
            $userId = Auth::id();
            $count = $this->notificationService->markAllAsRead($userId);

            return response()->json([
                'success' => true,
                'message' => "Marked {$count} notification(s) as read",
                'count' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to mark all notifications as read',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete notification
     * SECURITY: Verifies ownership before deleting
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $userId = Auth::id();
            $success = $this->notificationService->deleteNotification($id, $userId);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found or access denied'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete notification',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
