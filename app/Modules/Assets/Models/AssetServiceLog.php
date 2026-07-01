<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetServiceLog extends Model
{
    protected $fillable = [
        'asset_id',
        'service_date',
        'service_type',
        'notes',
        'serviced_by',
        'next_service_date',
        'logged_by',
    ];

    protected $casts = [
        'service_date'      => 'date',
        'next_service_date' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'logged_by');
    }
}
