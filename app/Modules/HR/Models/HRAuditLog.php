<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HRAuditLog extends Model
{
    use HasFactory;

    protected $table = 'hr_audit_logs';

    protected $fillable = [
        'user_id',
        'employee_id',
        'action',
        'model_type',
        'model_id',
        'message',
        'context',
        'ip_address'
    ];

    protected $casts = [
        'context' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function subject()
    {
        return $this->morphTo('model');
    }
}
