<?php

namespace App\Modules\Finance\PettyCash\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class PettyCashActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'transaction_type',
        'transaction_id',
        'description',
        'changes'
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime'
    ];

    public $timestamps = false; // We use only created_at via useCurrent() in migration

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
