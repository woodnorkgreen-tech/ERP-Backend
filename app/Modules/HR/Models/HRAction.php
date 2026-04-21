<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HRAction extends Model
{
    use HasFactory;

    protected $table = 'hr_actions';

    protected $fillable = [
        'employee_id',
        'action_type_id',
        'action_type', // Keep for backward compatibility/legacy records
        'previous_data',
        'new_data',
        'effective_date',
        'reason',
        'status',
        'recorded_by',
        'approved_by',
        'executed_at'
    ];

    protected $casts = [
        'previous_data' => 'array',
        'new_data' => 'array',
        'effective_date' => 'date',
        'executed_at' => 'datetime'
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

    /**
     * Get the action type.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(HRActionType::class, 'action_type_id');
    }

    /**
     * Get the user who approved the action.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the attachments for this action.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(HRActionAttachment::class, 'hr_action_id');
    }
}
