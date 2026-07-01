<?php

namespace App\Models;

use App\Modules\Notifications\Models\AppNotification;

/**
 * Compatibility model for older module services during their caller migration.
 * All records are persisted in the universal notification table.
 */
class Notification extends AppNotification
{
    protected $table = 'app_notifications';

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Notification $notification): void {
            if (!$notification->module) {
                $notification->module = self::moduleForType((string) $notification->type);
            }

            $notification->urgency ??= 'info';
        });
    }

    private static function moduleForType(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'requisition_') => 'finance',
            str_starts_with($type, 'task_'), str_starts_with($type, 'user_mentioned'),
                str_starts_with($type, 'universal_task_') => 'universal-task',
            default => 'projects',
        };
    }
}
