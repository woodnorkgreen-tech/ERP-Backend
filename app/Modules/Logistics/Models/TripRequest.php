<?php

namespace App\Modules\Logistics\Models;

use App\Modules\HR\Models\Employee;
use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripRequest extends Model
{
    use SoftDeletes;

    // timeline_status is a computed accessor (not a column) — appending it
    // means it's always present whenever a TripRequest is serialized
    // directly (e.g. DispatchBatchController's raw `response()->json($batch)`,
    // which doesn't go through TripRequestResource), not just wherever
    // someone remembered to add it manually. That's what lets the Dispatch
    // Board show a trip's loading/departure status without extra wiring.
    protected $appends = ['timeline_status'];

    // Default loading-duration presets for the Load Size picker on the
    // create forms (web + mobile) — purely a UI convenience; the value
    // that actually drives recalculateLoadingTimeline() is always whatever
    // ends up in estimated_loading_minutes, size-derived or hand-typed.
    public const LOAD_SIZE_DEFAULT_MINUTES = [
        'small'  => 15,
        'medium' => 30,
        'large'  => 60,
    ];

    protected $fillable = [
        'request_code',
        'context_type',
        'transport_arrangement',
        'project_id',
        'delivery_type_label',
        'requested_by_id',
        'priority',
        'pickup_location',
        'pickup_lat',
        'pickup_lng',
        'destination',
        'destination_lat',
        'destination_lng',
        'required_date',
        'loading_time',
        'departure_time',
        'setdown_time',
        // Backward-calculation from the delivery deadline.
        'required_delivery_at',
        'estimated_loading_minutes',
        'load_size',
        'estimated_travel_minutes',
        'buffer_minutes',
        'loading_start_by',
        'departure_by',
        'loading_responsible_id',
        'loading_started_at',
        'loading_ended_at',
        'loading_alert_state',
        'notes',
        'status',
        'approved_by_id',
        'approved_at',
        'rejection_reason',
        'assigned_driver_id',
        'assigned_vehicle_id',
        'vehicle_note',
        'assigned_by_id',
        'assigned_at',
        'assignment_notes',
        'started_at',
        'completed_at',
        'batch_id',
        'stop_order',
        'delivery_failed_at',
        'delivery_failure_reason',
        'failure_resolution',
        'failure_resolved_at',
        'failure_resolved_by_id',
    ];

    protected $casts = [
        'required_date'  => 'date',
        'approved_at'    => 'datetime',
        'assigned_at'    => 'datetime',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
        'delivery_failed_at'   => 'datetime',
        'failure_resolved_at'  => 'datetime',
        'setdown_time'   => 'datetime',
        'required_delivery_at' => 'datetime',
        'loading_start_by'     => 'datetime',
        'departure_by'         => 'datetime',
        'loading_started_at'   => 'datetime',
        'loading_ended_at'     => 'datetime',
        'pickup_lat'     => 'decimal:7',
        'pickup_lng'     => 'decimal:7',
        'destination_lat'=> 'decimal:7',
        'destination_lng'=> 'decimal:7',
    ];

    // ─── Relationships ────────────────────────────────────────────────────

    public function project(): BelongsTo
{
    return $this->belongsTo(ProjectEnquiry::class, 'project_id');
}

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'assigned_driver_id');
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_id');
    }

    public function loadingResponsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'loading_responsible_id');
    }

    // ─── Loading/departure backward-calculation ─────────────────────────────

    /**
     * required_delivery_at minus travel time minus buffer = when the
     * vehicle must depart; minus loading time again = when loading must
     * start. Kevin's own example: a 10am delivery needing 1h15 for each of
     * loading and travel means loading starts by 7:30am (earlier with a
     * buffer) — this is exactly that arithmetic, just generalised.
     *
     * Silently leaves loading_start_by/departure_by null when the inputs
     * aren't all present yet, rather than guessing at missing durations —
     * a trip without a deadline or duration estimates simply isn't using
     * auto-calculation (it can still use the plain loading_time/
     * departure_time fields from the Logistics Log merge instead).
     */
    public function recalculateLoadingTimeline(): void
    {
        if (!$this->required_delivery_at || $this->estimated_travel_minutes === null || $this->estimated_loading_minutes === null) {
            $this->loading_start_by = null;
            $this->departure_by = null;
            return;
        }

        $buffer = $this->buffer_minutes ?? 0;

        $departureBy = $this->required_delivery_at->copy()
            ->subMinutes($this->estimated_travel_minutes)
            ->subMinutes($buffer);

        $loadingStartBy = $departureBy->copy()->subMinutes($this->estimated_loading_minutes);

        $this->departure_by = $departureBy;
        $this->loading_start_by = $loadingStartBy;
    }

    /**
     * Where this trip stands against its own calculated timeline, for
     * display and for the reminder/escalation command to key off of.
     * Only meaningful when auto-calculation is in use (loading_start_by set)
     * — a manually-timed trip (plain loading_time/departure_time) has no
     * deadline to be overdue against, so it's always 'not_tracked'.
     */
    public function getTimelineStatusAttribute(): string
    {
        if (!$this->loading_start_by || !$this->departure_by) {
            return 'not_tracked';
        }
        if ($this->loading_ended_at) {
            return 'completed';
        }

        $now = now();

        if ($now->greaterThan($this->departure_by)) {
            return 'overdue_departure';
        }
        if ($this->loading_started_at) {
            return $now->greaterThan($this->departure_by) ? 'overdue_departure' : 'loading';
        }
        if ($now->greaterThan($this->loading_start_by)) {
            return 'overdue_loading';
        }
        if ($now->diffInMinutes($this->loading_start_by) <= 30) {
            return 'due_soon';
        }
        return 'on_track';
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('requested_by_id', $employeeId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'requested');
    }

    // ─── Auto-generate request_code ───────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (TripRequest $trip) {
            if (empty($trip->request_code)) {
                $year  = now()->format('Y');
                $count = static::withTrashed()
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $trip->request_code = 'TREQ-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });

        // Recompute loading_start_by/departure_by whenever any input to the
        // calculation changes, so they're never stale for the reminder
        // command to query against.
        static::saving(function (TripRequest $trip) {
            if ($trip->isDirty(['required_delivery_at', 'estimated_loading_minutes', 'estimated_travel_minutes', 'buffer_minutes'])) {
                $trip->recalculateLoadingTimeline();
            }
        });
    }
}
