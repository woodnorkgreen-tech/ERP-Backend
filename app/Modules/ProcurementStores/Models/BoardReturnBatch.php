<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class BoardReturnBatch extends Model
{
    protected $fillable = ['reference', 'job_ref', 'project_id', 'status', 'expected_count', 'received_count', 'missing_count', 'initiated_by', 'initiated_at', 'received_by', 'received_at', 'notes'];
    protected $casts = ['initiated_at' => 'datetime', 'received_at' => 'datetime'];
    public function items() { return $this->hasMany(BoardReturnBatchItem::class); }
    public function project() { return $this->belongsTo(\App\Models\Project::class); }
    public function initiator() { return $this->belongsTo(\App\Models\User::class, 'initiated_by'); }
    public function receiver() { return $this->belongsTo(\App\Models\User::class, 'received_by'); }
}
