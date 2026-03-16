<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GovernanceAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_enquiry_id',
        'user_id',
        'gate_type',
        'action_status',
        'model_type',
        'model_id',
        'message',
        'context',
        'ip_address'
    ];

    protected $casts = [
        'context' => 'array'
    ];

    public function enquiry()
    {
        return $this->belongsTo(\App\Models\ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
