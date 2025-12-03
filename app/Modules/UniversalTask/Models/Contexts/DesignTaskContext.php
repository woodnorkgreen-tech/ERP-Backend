<?php

namespace App\Modules\UniversalTask\Models\Contexts;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignTaskContext extends Model
{
    use HasFactory;

    protected $table = 'design_task_contexts';

    protected $fillable = [
        'task_id',
        'design_type',
        'design_assets',
        'current_revision',
        'revision_history',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'design_software',
        'design_specifications',
        'file_format',
        'color_palette',
        'fonts',
        'target_platform',
        'width_px',
        'height_px',
        'design_brief',
        'client_feedback',
        'reference_links',
        'requires_client_approval',
        'feedback_rounds',
        'final_delivery_date',
    ];

    protected $casts = [
        'design_assets' => 'array',
        'revision_history' => 'array',
        'approved_at' => 'datetime',
        'design_specifications' => 'array',
        'color_palette' => 'array',
        'fonts' => 'array',
        'reference_links' => 'array',
        'requires_client_approval' => 'boolean',
        'feedback_rounds' => 'integer',
        'current_revision' => 'integer',
        'width_px' => 'integer',
        'height_px' => 'integer',
        'final_delivery_date' => 'datetime',
    ];

    // ==================== Relationships ====================

    /**
     * Get the task that owns this design context.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who approved this design.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ==================== Methods ====================

    /**
     * Check if the design is approved.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Check if the design is rejected.
     */
    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * Check if the design needs revision.
     */
    public function needsRevision(): bool
    {
        return $this->approval_status === 'needs_revision';
    }

    /**
     * Increment the revision number and add to history.
     */
    public function incrementRevision(string $notes = null): void
    {
        $this->current_revision++;
        
        $revisionHistory = $this->revision_history ?? [];
        $revisionHistory[] = [
            'revision' => $this->current_revision,
            'created_at' => now()->toISOString(),
            'notes' => $notes,
        ];
        
        $this->revision_history = $revisionHistory;
        $this->save();
    }

    /**
     * Approve the design.
     */
    public function approve(int $userId, string $notes = null): void
    {
        $this->approval_status = 'approved';
        $this->approved_by = $userId;
        $this->approved_at = now();
        $this->approval_notes = $notes;
        $this->save();
    }

    /**
     * Reject the design.
     */
    public function reject(string $notes = null): void
    {
        $this->approval_status = 'rejected';
        $this->approval_notes = $notes;
        $this->feedback_rounds++;
        $this->save();
    }

    /**
     * Request revision for the design.
     */
    public function requestRevision(string $notes = null): void
    {
        $this->approval_status = 'needs_revision';
        $this->approval_notes = $notes;
        $this->feedback_rounds++;
        $this->save();
    }

    /**
     * Get the aspect ratio of the design.
     */
    public function getAspectRatio(): ?string
    {
        if (!$this->width_px || !$this->height_px) {
            return null;
        }

        $gcd = $this->gcd($this->width_px, $this->height_px);
        $ratioWidth = $this->width_px / $gcd;
        $ratioHeight = $this->height_px / $gcd;

        return "{$ratioWidth}:{$ratioHeight}";
    }

    /**
     * Calculate the greatest common divisor (helper for aspect ratio).
     */
    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }
}
