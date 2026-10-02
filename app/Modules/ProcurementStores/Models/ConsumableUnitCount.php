<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableUnitCount extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['system_quantity' => 'decimal:6', 'physical_quantity' => 'decimal:6', 'variance' => 'decimal:6'];
    public function review() { return $this->hasOne(ConsumableUnitCountReview::class, 'count_id'); }
    public function actor() { return $this->belongsTo(\App\Models\User::class, 'actor_id'); }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \DomainException('Physical-count evidence is immutable. Record a new count.'));
        static::deleting(fn () => throw new \DomainException('Physical-count evidence cannot be deleted.'));
    }
}
