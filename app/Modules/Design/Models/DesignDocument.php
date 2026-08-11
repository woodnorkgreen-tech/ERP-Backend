<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignDocument extends Model
{
    protected $fillable = [
        'design_job_id',
        'design_item_id',
        'document_type',
        'name',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'version',
        'status',
        'metadata',
        'uploaded_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'version' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(DesignJob::class, 'design_job_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(DesignItem::class, 'design_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
