<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileUpdateRequest extends Model
{
    use HasFactory;

    protected $table = 'profile_update_requests';

    protected $fillable = [
        'employee_id',
        'requested_data',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at'
    ];

    protected $casts = [
        'requested_data' => 'array',
        'reviewed_at' => 'datetime'
    ];

    /**
     * Get the employee that the request belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user who reviewed the request.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
