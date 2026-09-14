<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceWorkAssignmentEvent extends Model
{
    protected $fillable = [
        'work_type', 'source_id', 'event', 'from_user_id', 'to_user_id', 'actor_id', 'note',
    ];

    public function fromUser(): BelongsTo { return $this->belongsTo(User::class, 'from_user_id'); }
    public function toUser(): BelongsTo { return $this->belongsTo(User::class, 'to_user_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
