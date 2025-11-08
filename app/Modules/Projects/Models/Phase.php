<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Phase extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_enquiry_id',
        'name',
        'description',
        'order',
        'status',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'order' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // Relationships
    public function projectEnquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Enquiry::class, 'project_enquiry_id');
    }
}
