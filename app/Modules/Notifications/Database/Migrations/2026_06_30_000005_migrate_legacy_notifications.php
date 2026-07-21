<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications') || !Schema::hasTable('app_notifications')) {
            return;
        }

        // Some legacy rows reference a user_id that no longer exists in
        // `users` (hard-deleted before soft-deletes existed) — inserting
        // those would violate app_notifications' FK constraint. Skip them;
        // there's no recipient left to show the notification to anyway, and
        // the source table is dropped at the end of this migration regardless.
        $validUserIds = DB::table('users')->pluck('id')->flip();

        DB::table('notifications')->orderBy('id')->chunk(200, function ($notifications) use ($validUserIds): void {
            foreach ($notifications as $notification) {
                if (!$notification->user_id
                    || !$validUserIds->has($notification->user_id)
                    || DB::table('app_notifications')->where('id', $notification->id)->exists()
                ) {
                    continue;
                }

                $data = $this->decodeData($notification->data ?? null);
                $type = $notification->type ?? 'legacy_notification';

                DB::table('app_notifications')->insert([
                    'id' => $notification->id,
                    'user_id' => $notification->user_id,
                    'module' => $this->moduleForType($type),
                    'type' => $type,
                    'title' => $notification->title ?? $data['title'] ?? 'Notification',
                    'message' => $notification->message ?? $data['message'] ?? $data['body'] ?? '',
                    'data' => json_encode($data),
                    'urgency' => $data['urgency'] ?? 'info',
                    'is_read' => (bool) ($notification->is_read ?? $notification->read_at ?? false),
                    'read_at' => $notification->read_at ?? null,
                    'is_starred' => false,
                    'starred_at' => null,
                    'created_at' => $notification->created_at ?? now(),
                    'updated_at' => $notification->updated_at ?? now(),
                ]);
            }
        });

        Schema::drop('notifications');
    }

    public function down(): void
    {
        // The legacy table is intentionally not recreated; app_notifications is authoritative.
    }

    private function decodeData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode((string) $data, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function moduleForType(string $type): string
    {
        return match (true) {
            str_contains($type, 'leave'), str_contains($type, 'incident') => 'hr',
            str_contains($type, 'requisition') => 'finance',
            str_contains($type, 'task'), str_contains($type, 'mention') => 'universal-task',
            default => 'projects',
        };
    }
};
