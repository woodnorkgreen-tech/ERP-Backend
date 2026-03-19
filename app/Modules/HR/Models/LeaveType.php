<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'days_per_year',
        'color',
        'icon',
        'description',
        'is_active',
        'requires_attachment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_attachment' => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
