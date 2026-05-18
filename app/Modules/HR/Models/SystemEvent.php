<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemEvent extends Model
{
    use HasFactory;

    protected $table = 'system_events';

    // Disable updated_at since events are immutable
    const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'entity_type',
        'entity_id',
        'action',
        'payload',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'payload' => 'json',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Helper to log an event
     */
    public static function log(string $action, string $entityType, $entityId, array $payload = [], ?int $actorId = null): void
    {
        self::create([
            'actor_id' => $actorId ?? auth()->id(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'payload' => $payload,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
