<?php

namespace App\Modules\ArchivalTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\User;

class ArchivalReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        // Section 1: Project Information
        'client_name',
        'project_code',
        'project_officer',
        'start_date',
        'end_date',
        'site_location',
        // Section 2: Project Scope
        'project_scope',
        // Section 3: Procurement
        'materials_mrf_attached',
        'items_sourced_externally',
        'procurement_challenges',
        // Section 4: Fabrication
        'production_start_date',
        'packaging_labeling_status',
        'materials_used_in_production',
        // Section 5: Team & Setup
        'team_captain',
        'setup_team_assigned',
        'branding_team_assigned',
        'all_deliverables_available',
        'setup_aligned_to_schedule',
        'delays_occurred',
        'delay_reasons',
        'deliverables_checked',
        'site_organization',
        'cleanliness_rating',
        'general_findings',
        'site_readiness_notes',
        // Section 6: Client Handover
        'handover_date',
        'client_rating',
        'client_remarks',
        'print_clarity_rating',
        'printworks_accuracy_rating',
        'installation_precision_comments',
        'setup_speed_flow',
        'team_coordination',
        'efficiency_remarks',
        'client_kept_informed',
        'client_satisfaction',
        'communication_comments',
        'delivered_on_schedule',
        'delivery_condition',
        'delivery_issues',
        'delivery_notes',
        'team_professionalism',
        'client_confidence',
        'professionalism_feedback',
        'recommendations_action_points',
        // Section 7: Set-Down
        'setdown_date',
        'items_condition_returned',
        'site_clearance_status',
        'outstanding_items',
        // Section 8: Attachments
        'attachments',
        // Section 9: Signatures
        'project_officer_signature',
        'project_officer_sign_date',
        'reviewed_by',
        'reviewer_sign_date',
        // Status
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'production_start_date' => 'date',
        'handover_date' => 'date',
        'setdown_date' => 'date',
        'project_officer_sign_date' => 'date',
        'reviewer_sign_date' => 'date',
        'materials_mrf_attached' => 'boolean',
        'all_deliverables_available' => 'boolean',
        'setup_aligned_to_schedule' => 'boolean',
        'delays_occurred' => 'boolean',
        'deliverables_checked' => 'boolean',
        'client_kept_informed' => 'boolean',
        'delivered_on_schedule' => 'boolean',
        'delivery_issues' => 'boolean',
        'client_confidence' => 'boolean',
        'attachments' => 'array',
    ];

    protected $appends = [
        'attachment_urls',
    ];

    /**
     * Relationships
     */
    public function enquiryTask(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'enquiry_task_id');
    }

    public function setupItems(): HasMany
    {
        return $this->hasMany(ArchivalSetupItem::class);
    }

    public function itemPlacements(): HasMany
    {
        return $this->hasMany(ArchivalItemPlacement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Accessors
     */
    public function getAttachmentUrlsAttribute(): array
    {
        if (!$this->attachments) {
            return [];
        }

        return array_map(function ($attachment) {
            if (isset($attachment['path'])) {
                return [
                    ...$attachment,
                    'url' => \Storage::disk('public')->url($attachment['path']),
                ];
            }
            return $attachment;
        }, $this->attachments);
    }

    /**
     * Scopes
     */
    public function scopeByTask($query, $taskId)
    {
        return $query->where('enquiry_task_id', $taskId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
