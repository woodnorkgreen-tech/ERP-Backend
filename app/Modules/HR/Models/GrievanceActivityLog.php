<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class GrievanceActivityLog extends Model
{
    protected $table = 'grievance_activity_logs';

    protected $fillable = [
        'grievance_id',
        'user_id',
        'action',
        'details',
    ];

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(int $grievanceId, string $action, ?string $details = null): void
    {
        self::create([
            'grievance_id' => $grievanceId,
            'user_id' => auth()->id(),
            'action' => $action,
            'details' => $details,
        ]);
    }
}