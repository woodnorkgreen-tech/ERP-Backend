<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HRActionAttachment extends Model
{
    use HasFactory;

    protected $table = 'hr_action_attachments';

    protected $fillable = [
        'hr_action_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'uploaded_by'
    ];

    /**
     * Get the HR action this attachment belongs to.
     */
    public function hrAction(): BelongsTo
    {
        return $this->belongsTo(HRAction::class);
    }

    /**
     * Get the user who uploaded the attachment.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
