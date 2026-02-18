<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderTaskEvidence extends Model
{
    use HasFactory;

    protected $table = 'work_order_task_evidence';

    protected $fillable = [
        'work_order_task_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by'
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkOrderTask::class, 'work_order_task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }
}
