<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableUnitMovement extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'decimal:6', 'balance_before' => 'decimal:6', 'balance_after' => 'decimal:6', 'value' => 'decimal:2', 'unit_cost' => 'decimal:8'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \DomainException('Unit movement history is immutable; post a correction movement.'));
        static::deleting(fn () => throw new \DomainException('Unit movement history cannot be deleted.'));
    }
    public function project() { return $this->belongsTo(\App\Models\Project::class); }
    public function actor() { return $this->belongsTo(\App\Models\User::class, 'actor_id'); }
    public function inventoryLog() { return $this->belongsTo(InventoryLog::class); }
}
