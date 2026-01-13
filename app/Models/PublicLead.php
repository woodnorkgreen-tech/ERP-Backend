<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\HR\Models\Department;

class PublicLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'company_name',
        'department_id',
        'service_interest',
        'description',
        'how_did_you_hear',
        'source',
        'status',
        'processed_at',
        'processed_by',
        'converted_client_id',
        'converted_enquiry_id',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
