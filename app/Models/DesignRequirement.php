<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        'client_key',
        'title',
        'category',
        'description',
        'status',
        'asset_id',
    ];

    /**
     * Relationship to the enquiry task
     */
    public function enquiryTask(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'enquiry_task_id');
    }

    /**
     * Relationship to the fulfilled asset
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(DesignAsset::class, 'asset_id');
    }
}
