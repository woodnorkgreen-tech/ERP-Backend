<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Generic Finance evidence/attachment (shared foundation — see the migration
 * for the reasoning against a BillAttachment/PettyCashAttachment/
 * VoucherAttachment split).
 */
class FinanceAttachment extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'evidence_type',
        'file_path', 'original_filename', 'mime_type', 'file_size',
        'reference', 'description', 'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    /**
     * Report 61: the storage path is an internal detail. Every file is served
     * through a controlled download route that checks the source; a caller that
     * serialised the model (cash counts did) must never hand the path out.
     */
    protected $hidden = ['file_path'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
