<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class IncidentActivityLog extends Model
{
    protected $table = 'incident_activity_logs';
    
    protected $fillable = [
        'incident_id',
        'user_id',
        'action',
        'details',
    ];
    
    /**
     * Get the incident
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }
    
    /**
     * Get the user who performed the action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * Log an activity
     */
    public static function log(int $incidentId, string $action, ?string $details = null, ?int $userId = null): self
    {
        return self::create([
            'incident_id' => $incidentId,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'details' => $details,
        ]);
    }
}

