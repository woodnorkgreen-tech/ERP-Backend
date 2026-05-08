<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class DisciplinaryActivityLog extends Model
{
    protected $table = 'disciplinary_activity_logs';

    protected $fillable = [
        'case_id',
        'user_id',
        'action',
        'details',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(int $caseId, string $action, ?string $details = null): void
    {
        self::create([
            'case_id' => $caseId,
            'user_id' => auth()->id(),
            'action' => $action,
            'details' => $details,
        ]);
    }
}