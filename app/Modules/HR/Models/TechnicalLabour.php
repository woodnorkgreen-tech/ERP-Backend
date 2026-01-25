<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalLabour extends Model
{
    use HasFactory;

    protected $table = 'technical_labours';

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'id_number',
        'specialization',
        'day_rate',
        'status',
        'rating',
        'notes'
    ];

    protected $casts = [
        'day_rate' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    /**
     * Scope to filter active technical labour.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
