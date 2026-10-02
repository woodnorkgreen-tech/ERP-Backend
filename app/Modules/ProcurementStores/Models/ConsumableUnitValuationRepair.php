<?php
namespace App\Modules\ProcurementStores\Models;
use Illuminate\Database\Eloquent\Model;
class ConsumableUnitValuationRepair extends Model {
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['previous_unit_cost'=>'decimal:8','new_unit_cost'=>'decimal:8','previous_value'=>'decimal:2','new_value'=>'decimal:2','created_at'=>'datetime'];
    protected static function booted(): void {
        static::updating(fn () => throw new \DomainException('Review and valuation evidence is immutable.'));
        static::deleting(fn () => throw new \DomainException('Review and valuation evidence cannot be deleted.'));
    }
    public function actor() { return $this->belongsTo(\App\Models\User::class, 'actor_id'); }
}
