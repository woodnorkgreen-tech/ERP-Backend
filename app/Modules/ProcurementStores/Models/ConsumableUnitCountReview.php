<?php
namespace App\Modules\ProcurementStores\Models;
use Illuminate\Database\Eloquent\Model;
class ConsumableUnitCountReview extends Model {
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['system_quantity'=>'decimal:6','physical_quantity'=>'decimal:6','variance'=>'decimal:6','resulting_quantity'=>'decimal:6','reviewed_at'=>'datetime'];
    protected static function booted(): void {
        static::updating(fn () => throw new \DomainException('Review and valuation evidence is immutable.'));
        static::deleting(fn () => throw new \DomainException('Review and valuation evidence cannot be deleted.'));
    }
    public function actor() { return $this->belongsTo(\App\Models\User::class, 'reviewer_id'); }
}
