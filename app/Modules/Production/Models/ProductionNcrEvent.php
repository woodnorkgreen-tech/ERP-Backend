<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionNcrEvent extends Model
{
    use HasFactory;

    protected $table = 'production_ncr_events';

    protected $fillable = [
        'ncr_id',
        'event_type',
        'from_status',
        'to_status',
        'note',
        'meta',
        'performed_by',
        'performed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'performed_at' => 'datetime',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(ProductionNcr::class, 'ncr_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'performed_by');
    }
}
