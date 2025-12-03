<?php

namespace App\Modules\UniversalTask\Models\Contexts;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsTaskContext extends Model
{
    use HasFactory;

    protected $table = 'logistics_task_contexts';

    protected $fillable = [
        'task_id',
        'transport_type',
        'transport_items',
        'checklist_items',
        'pickup_location',
        'delivery_location',
        'scheduled_pickup_time',
        'scheduled_delivery_time',
        'actual_pickup_time',
        'actual_delivery_time',
        'vehicle_registration',
        'driver_id',
        'special_instructions',
        'estimated_distance_km',
        'actual_distance_km',
        'cargo_type',
        'cargo_weight_kg',
        'cargo_volume_m3',
        'requires_signature',
        'signature_path',
        'photos',
        'delivery_notes',
    ];

    protected $casts = [
        'transport_items' => 'array',
        'checklist_items' => 'array',
        'scheduled_pickup_time' => 'datetime',
        'scheduled_delivery_time' => 'datetime',
        'actual_pickup_time' => 'datetime',
        'actual_delivery_time' => 'datetime',
        'estimated_distance_km' => 'decimal:2',
        'actual_distance_km' => 'decimal:2',
        'cargo_weight_kg' => 'decimal:2',
        'cargo_volume_m3' => 'integer',
        'requires_signature' => 'boolean',
        'photos' => 'array',
    ];

    // ==================== Relationships ====================

    /**
     * Get the task that owns this logistics context.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the driver assigned to this logistics task.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    // ==================== Methods ====================

    /**
     * Check if the delivery is completed.
     */
    public function isDeliveryCompleted(): bool
    {
        return !is_null($this->actual_delivery_time);
    }

    /**
     * Check if the pickup is completed.
     */
    public function isPickupCompleted(): bool
    {
        return !is_null($this->actual_pickup_time);
    }

    /**
     * Calculate the delivery delay in hours.
     */
    public function getDeliveryDelay(): ?float
    {
        if (!$this->scheduled_delivery_time || !$this->actual_delivery_time) {
            return null;
        }

        return $this->actual_delivery_time->diffInHours($this->scheduled_delivery_time, false);
    }

    /**
     * Get the completion percentage of checklist items.
     */
    public function getChecklistCompletionPercentage(): float
    {
        if (empty($this->checklist_items) || !is_array($this->checklist_items)) {
            return 0.0;
        }

        $total = count($this->checklist_items);
        $completed = collect($this->checklist_items)->where('completed', true)->count();

        return $total > 0 ? round(($completed / $total) * 100, 2) : 0.0;
    }
}
