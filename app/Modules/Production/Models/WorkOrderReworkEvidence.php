<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderReworkEvidence extends Model
{
    use HasFactory;

    protected $table = 'work_order_rework_evidence';

    protected $fillable = [
        'work_order_rework_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by'
    ];

    public function rework(): BelongsTo
    {
        return $this->belongsTo(WorkOrderRework::class, 'work_order_rework_id');
    }
}
