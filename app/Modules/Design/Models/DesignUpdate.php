<?php

namespace App\Modules\Design\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignUpdate extends Model
{
    protected $fillable = ['design_item_id', 'note', 'created_by'];
    public function item(): BelongsTo { return $this->belongsTo(DesignItem::class, 'design_item_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
