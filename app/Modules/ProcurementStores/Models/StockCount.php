<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    public const MODE_CYCLE = 'cycle_count';
    public const MODE_OPENING = 'opening_inventory';

    protected $fillable = ['count_number', 'mode', 'warehouse_code', 'status', 'counted_on', 'catalogue_snapshot_at', 'notes', 'created_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes'];
    protected $casts = ['counted_on' => 'date', 'catalogue_snapshot_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    public function items() { return $this->hasMany(StockCountItem::class); }
    public function creator() { return $this->belongsTo(\App\Models\User::class, 'created_by'); }
    public function submitter() { return $this->belongsTo(\App\Models\User::class, 'submitted_by'); }
    public function reviewer() { return $this->belongsTo(\App\Models\User::class, 'reviewed_by'); }
}
