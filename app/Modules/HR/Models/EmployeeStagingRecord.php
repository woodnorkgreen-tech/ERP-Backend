<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeStagingRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'batch_name',
        'email',
        'employee_id',
        'id_number',
        'matched_employee_id',
        'staged_data',
        'status',
        'uploaded_by',
    ];

    protected $casts = [
        'staged_data' => 'array',
    ];

    /**
     * Get the matched employee record.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_employee_id');
    }

    /**
     * Get the user who uploaded the dump batch.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Scope query to active (staged or partially applied) records.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['staged', 'partially_applied']);
    }
}
