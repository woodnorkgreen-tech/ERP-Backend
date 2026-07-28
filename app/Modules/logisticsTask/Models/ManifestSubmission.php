<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestSubmission extends Model
{
    protected $table = 'logistics_manifest_submissions';

    protected $fillable = [
        'manifest_link_id', 'submitted_by', 'name', 'quantity', 'unit', 'main_category',
        'sub_type', 'is_returnable', 'description', 'status', 'review_note', 'reviewed_by',
        'reviewed_at', 'transport_item_id',
    ];

    protected $casts = ['is_returnable' => 'boolean', 'reviewed_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(ManifestSubmissionLink::class, 'manifest_link_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'submitted_by');
    }
}
