<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class AppNotification extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'module',
        'type',
        'title',
        'message',
        'data',
        'urgency',
        'is_read',
        'read_at',
        'is_starred',
        'starred_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'is_starred' => 'boolean',
        'starred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        $clearUnreadCount = function (AppNotification $notification): void {
            Cache::forget(self::unreadCountCacheKey($notification->user_id));
        };

        static::created($clearUnreadCount);
        static::updated($clearUnreadCount);
        static::deleted($clearUnreadCount);
    }

    public static function unreadCountCacheKey(int $userId): string
    {
        return "notifications:unread-count:{$userId}";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsRead(): void
    {
        if ($this->is_read) {
            return;
        }

        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}
