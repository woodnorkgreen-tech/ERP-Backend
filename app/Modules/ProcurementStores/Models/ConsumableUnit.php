<?php

namespace App\Modules\ProcurementStores\Models;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Database\Eloquent\Model;

class ConsumableUnit extends Model
{
    protected $guarded = ['id'];
    protected $appends = ['operational_reference'];
    public function getOperationalReferenceAttribute(): string { return ($this->material?->material_code ?? 'Material '.$this->material_id).' / '.$this->unit_code; }
    public function valuationRepairs() { return $this->hasMany(ConsumableUnitValuationRepair::class)->orderByDesc('id'); }
    protected $casts = [
        'original_quantity' => 'decimal:6', 'remaining_quantity' => 'decimal:6',
        'unit_cost' => 'decimal:8', 'original_value' => 'decimal:2', 'remaining_value' => 'decimal:2',
        'received_at' => 'datetime', 'opened_at' => 'datetime', 'depleted_at' => 'datetime',
    ];
    protected static function booted(): void
    {
        static::updating(function (self $unit) {
            if (($unit->isDirty('unit_code') && !str_starts_with((string) $unit->getOriginal('unit_code'), 'CU-PENDING-')) || $unit->isDirty(['material_id', 'parent_unit_id', 'original_quantity', 'source_log_id', 'source_receipt_id'])) throw new \DomainException('Controlled-unit identity and source are immutable.');
        });
        static::deleting(fn () => throw new \DomainException('Controlled-unit identity and lineage cannot be deleted.'));
    }
    public function material() { return $this->belongsTo(LibraryMaterial::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_unit_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_unit_id'); }
    public function movements() { return $this->hasMany(ConsumableUnitMovement::class)->orderBy('id'); }
    public function counts() { return $this->hasMany(ConsumableUnitCount::class)->orderByDesc('id'); }
}
