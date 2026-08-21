<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    protected $fillable = ['count_number', 'warehouse_code', 'status', 'counted_on', 'notes', 'created_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes'];
    protected $casts = ['counted_on' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    public function items() { return $this->hasMany(StockCountItem::class); }
    public function creator() { return $this->belongsTo(\App\Models\User::class, 'created_by'); }
    public function submitter() { return $this->belongsTo(\App\Models\User::class, 'submitted_by'); }
    public function reviewer() { return $this->belongsTo(\App\Models\User::class, 'reviewed_by'); }
}
