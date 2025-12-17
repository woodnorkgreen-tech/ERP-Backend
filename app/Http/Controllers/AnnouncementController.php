<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Get all announcements for the authenticated user.
     */
   public function index(Request $request)
{
    $user = auth()->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not authenticated'
        ], 401);
    }

    $announcements = Announcement::with(['fromUser', 'toEmployee', 'toDepartment', 'readByUsers'])
        ->forUser($user)
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($announcement) use ($user) {
            $isCreator = $announcement->from_user_id === $user->id;
            
            return [
                'id' => $announcement->id,
                'message' => $announcement->message,
                'from_name' => $announcement->from_name,
                'to_name' => $announcement->to_name,
                'type' => $announcement->type,
                'created_at' => $announcement->created_at->format('M d, Y'),
                'is_read' => !$isCreator ? $announcement->isReadBy($user->id) : false,
                'is_creator' => $isCreator,
                'read_count' => $announcement->readByUsers->count(),
                'recipient_has_read' => $isCreator ? $announcement->hasBeenReadByRecipients() : null,
            ];
        });

    return response()->json([
        'success' => true,
        'data' => $announcements
    ]);
}
/**
 * Create a new announcement.
 */
public function store(Request $request)
{
    $user = auth()->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not authenticated'
        ], 401);
    }

    $validated = $request->validate([
        'message' => 'required|string',
        'type' => 'required|in:employee,department',
        'to_employee_id' => 'required_if:type,employee|integer|nullable',
        'to_department_id' => 'required_if:type,department|integer|nullable',
    ]);

    // ✅ Use authenticated user's ID, NOT from request
    $announcement = Announcement::create([
        'message' => $validated['message'],
        'from_user_id' => $user->id,  // ✅ Use auth user, not request
        'type' => $validated['type'],
        'to_employee_id' => $validated['to_employee_id'] ?? null,
        'to_department_id' => $validated['to_department_id'] ?? null,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Announcement created successfully',
        'data' => $announcement
    ]);
}
    /**
     * Mark announcement as read.
     */
    public function markAsRead(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated'
            ], 401);
        }

        $validated = $request->validate([
            'announcement_id' => 'required|exists:announcements,id'
        ]);

        $announcement = Announcement::findOrFail($validated['announcement_id']);

        // ✅ Prevent creator from marking their own announcement as read
        if ($announcement->from_user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot mark your own announcement as read'
            ], 403);
        }

        // Check if user is allowed to read this announcement
        $canRead = Announcement::forUser($user)
            ->where('id', $announcement->id)
            ->exists();

        if (!$canRead) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to read this announcement'
            ], 403);
        }

        $announcement->markAsReadBy($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Announcement marked as read'
        ]);
    }

    /**
     * Get unread count for user.
     */
    public function unreadCount(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated'
            ], 401);
        }

        $count = Announcement::forUser($user)
            ->where('from_user_id', '!=', $user->id) // ✅ Don't count own announcements
            ->unreadForUser($user->id)
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count
        ]);
    }

    /**
     * Delete an announcement (only by creator).
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated'
            ], 401);
        }

        $announcement = Announcement::findOrFail($id);

        if ($announcement->from_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this announcement'
            ], 403);
        }

        $announcement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Announcement deleted successfully'
        ]);
    }
}