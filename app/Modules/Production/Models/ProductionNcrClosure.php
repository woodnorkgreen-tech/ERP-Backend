<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionNcrClosure extends Model
{
    use HasFactory;

    protected $table = 'production_ncr_closures';

    protected $fillable = [
        'ncr_id',
        'closure_type',
        'verification_result',
        'closure_summary',
        'effectiveness_note',
        'effectiveness_review_required',
        'effectiveness_review_date',
        'lessons_learned',
        'verified_by',
        'verified_at',
        'created_by',
    ];

    protected $casts = [
        'effectiveness_review_required' => 'boolean',
        'effectiveness_review_date' => 'date',
        'verified_at' => 'datetime',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(ProductionNcr::class, 'ncr_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
