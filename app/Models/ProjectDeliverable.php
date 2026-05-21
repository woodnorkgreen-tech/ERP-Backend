<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDeliverable extends Model
{
    use HasFactory;

    protected $table = 'project_deliverables';

    protected $fillable = [
        'enquiry_id',
        'uuid',
        'name',
        'classification',
        'status',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($deliverable) {
            if (empty($deliverable->uuid)) {
                $deliverable->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });
    }

    /**
     * Get the enquiry that owns the deliverable.
     */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'enquiry_id');
    }
}
