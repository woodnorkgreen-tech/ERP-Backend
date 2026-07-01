<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Models\AppNotificationPreference;
use App\Modules\Notifications\Models\UserDeviceToken;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string'],
            'filter' => ['nullable', Rule::in(['unread', 'starred', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if (!empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }

        if (($validated['filter'] ?? 'all') === 'unread') {
            $query->where('is_read', false);
        }

        if (($validated['filter'] ?? 'all') === 'starred') {
            $query->where('is_starred', true);
        }

        if (!empty($validated['search'])) {
            $term = '%' . addcslashes($validated['search'], '%_') . '%';
            $query->where(function ($query) use ($term) {
                $query->where('title', 'like', $term)
                    ->orWhere('message', 'like', $term)
                    ->orWhere('type', 'like', $term);
            });
        }

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $count = Cache::remember(
            AppNotification::unreadCountCacheKey($userId),
            now()->addSeconds((int) config('notifications.unread_count_cache_ttl', 15)),
            fn () => AppNotification::query()
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->count()
        );

        return response()->json(['count' => $count]);
    }

    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $totalsByModule = AppNotification::query()
            ->where('user_id', $userId)
            ->selectRaw('module, count(*) as total')
            ->groupBy('module')
            ->pluck('total', 'module');

        return response()->json([
            'total' => $totalsByModule->sum(),
            'starred' => AppNotification::query()
                ->where('user_id', $userId)
                ->where('is_starred', true)
                ->count(),
            'modules' => $totalsByModule
                ->map(fn (int $total, string $module) => ['module' => $module, 'total' => $total])
                ->values(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationForUser($request, $id);
        $notification->markAsRead();

        return response()->json(['data' => $notification->fresh()]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationForUser($request, $id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markManyAsRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
        ]);

        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $validated['ids'])
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string'],
        ]);

        $query = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false);

        if (!empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }

        $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function toggleStar(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationForUser($request, $id);
        $starred = !$notification->is_starred;

        $notification->update([
            'is_starred' => $starred,
            'starred_at' => $starred ? now() : null,
        ]);

        return response()->json(['data' => $notification->fresh()]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $preferences = AppNotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->groupBy('type');

        return response()->json([
            'data' => $preferences,
            'channels' => config('notifications.channels', []),
            'implemented_channels' => config('notifications.implemented_channels', []),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', 'string', Rule::in(array_keys(config('notifications.types', [])))],
            'preferences.*.channel' => ['required', 'string', Rule::in(config('notifications.channels', []))],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($validated['preferences'] as $preference) {
            AppNotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'type' => $preference['type'],
                    'channel' => $preference['channel'],
                ],
                ['enabled' => $preference['enabled']]
            );
        }

        return $this->preferences($request);
    }

    public function types(Request $request, NotificationService $notificationService): JsonResponse
    {
        $types = collect(config('notifications.types', []))
            ->filter(fn (array $type) => $notificationService->userCanSeeModule($request->user(), $type['module']))
            ->all();

        return response()->json([
            'data' => $types,
            'channels' => config('notifications.channels', []),
            'implemented_channels' => config('notifications.implemented_channels', []),
        ]);
    }

    public function registerDeviceToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'player_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
        ]);

        $token = UserDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'player_id' => $validated['player_id'],
            ],
            ['platform' => $validated['platform']]
        );

        return response()->json(['data' => $token], 201);
    }

    protected function notificationForUser(Request $request, string $id): AppNotification
    {
        return AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();
    }
}
