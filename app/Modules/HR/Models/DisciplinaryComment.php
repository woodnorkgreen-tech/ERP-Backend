<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class DisciplinaryComment extends Model
{
    protected $table = 'disciplinary_comments';

    protected $fillable = [
        'case_id',
        'user_id',
        'comment',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}