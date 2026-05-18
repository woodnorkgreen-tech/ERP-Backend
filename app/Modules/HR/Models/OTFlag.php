<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OTFlag extends Model
{
    use HasFactory;

    protected $table = 'ot_flags';

    protected $fillable = [
        'ot_entry_id',
        'type',
        'severity',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function otEntry(): BelongsTo
    {
        return $this->belongsTo(OTEntry::class, 'ot_entry_id');
    }
}
