<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'salary',
        'valid_from',
        'valid_to',
        'created_by'
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'salary' => 'decimal:2'
    ];

    /**
     * Get the employee that this history belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user who created this record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
