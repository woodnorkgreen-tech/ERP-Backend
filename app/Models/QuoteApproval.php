<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteApproval extends Model
{
    protected $guarded = [];

    protected $casts = [
        'approval_date' => 'date',
        'quote_amount' => 'decimal:2',
        'quote_data' => 'array',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'enquiry_id');
    }
}
