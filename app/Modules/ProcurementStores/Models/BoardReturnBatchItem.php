<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

class BoardReturnBatchItem extends Model
{
    protected $fillable = ['board_return_batch_id', 'board_id', 'status', 'condition_grade', 'outcome', 'notes', 'received_at'];
    protected $casts = ['received_at' => 'datetime'];
    public function batch() { return $this->belongsTo(BoardReturnBatch::class, 'board_return_batch_id'); }
    public function board() { return $this->belongsTo(Board::class); }
}
