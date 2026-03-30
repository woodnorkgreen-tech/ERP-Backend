<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HRAction extends Model
{
    use HasFactory;

    protected $table = 'hr_actions';

    protected $fillable = [
        'employee_id',
        'action_type',
        'previous_data',
        'new_data',
        'effective_date',
        'reason',
        'recorded_by'
    ];

    protected $casts = [
        'previous_data' => 'array',
        'new_data' => 'array',
        'effective_date' => 'date'
    ];

    /**
     * Get the employee that the action was performed on.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user who recorded the action.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
