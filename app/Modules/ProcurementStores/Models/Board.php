<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Models\User;

class Board extends Model
{
    protected $table = 'boards';

    protected $fillable = [
        'tracking_code',
        'library_material_id',
        'batch_number',
        'length',
        'width',
        'thickness',
        'area_m2',
        'current_value',
        'condition_grade',
        'status',
        'parent_board_id',
        'is_offcut',
        'label_printed',
        'label_printed_by',
        'label_printed_at',
        'assigned_job_ref',
        'created_by',
    ];

    protected $casts = [
        'length'           => 'integer',
        'width'            => 'integer',
        'thickness'        => 'integer',
        'area_m2'          => 'float',
        'current_value'    => 'float',
        'is_offcut'        => 'boolean',
        'label_printed'    => 'boolean',
        'label_printed_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function libraryMaterial(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'library_material_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(BoardMovement::class)->orderBy('ts');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'parent_board_id');
    }

    public function offcuts(): HasMany
    {
        return $this->hasMany(Board::class, 'parent_board_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeAvailable($query)
    {
        return $query->where('status', 'Available');
    }

    public function scopeOnJob($query)
    {
        return $query->whereIn('status', ['Allocated', 'At Station', 'WIP']);
    }

    public function scopeByJob($query, string $jobRef)
    {
        return $query->where('assigned_job_ref', $jobRef);
    }

    // ─── State machine ────────────────────────────────────────────────────────

    /**
     * Check if this board can transition to the given status.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = config('boards.valid_transitions')[$this->status] ?? [];
        return in_array($newStatus, $allowed);
    }

    /**
     * Transition board to a new status, recording a movement.
     * Condition grade and scrap reason are stored on the movement for full audit trail,
     * and the board's condition_grade is updated whenever a grade is supplied.
     * Throws \InvalidArgumentException if the transition is not allowed.
     */
    public function transitionTo(
        string  $newStatus,
        ?int    $userId          = null,
        ?string $notes           = null,
        ?string $jobRef          = null,
        ?string $conditionGrade  = null,
        ?string $scrapReasonCode = null,
    ): void {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition board [{$this->tracking_code}] from [{$this->status}] to [{$newStatus}]."
            );
        }

        $fromStatus  = $this->status;
        $previousJob = $this->assigned_job_ref;
        $this->status = $newStatus;

        if ($jobRef !== null) {
            $this->assigned_job_ref = $jobRef;
        }

        // Clear job assignment when board returns to stores
        if (in_array($newStatus, ['Available', 'Quarantine'])) {
            $this->assigned_job_ref = null;
        }

        // Update the board's own condition grade whenever one is provided
        if ($conditionGrade !== null) {
            $this->condition_grade = $conditionGrade;
        }

        $this->save();

        // Append immutable movement record — every field relevant to this transition
        BoardMovement::create([
            'board_id'          => $this->id,
            'from_status'       => $fromStatus,
            'to_status'         => $newStatus,
            'performed_by'      => $userId,
            'notes'             => $notes,
            'job_ref'           => $previousJob ?? $jobRef,
            'condition_grade'   => $conditionGrade,
            'scrap_reason_code' => $scrapReasonCode,
        ]);
    }

    /**
     * Fluent status check — $board->hasStatus('Available')
     */
    public function hasStatus(string $status): bool
    {
        return $this->status === $status;
    }

    /**
     * Compute area_m2 from current dimensions.
     */
    public function computeArea(): float
    {
        return round(($this->length * $this->width) / 1_000_000, 4);
    }
}
