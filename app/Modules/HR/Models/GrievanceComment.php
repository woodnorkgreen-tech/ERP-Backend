<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class GrievanceComment extends Model
{
    protected $table = 'grievance_comments';

    protected $fillable = [
        'grievance_id',
        'user_id',
        'comment',
    ];

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}