<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

class BoardRequest extends Model
{
    protected $table = 'board_requests';

    protected $fillable = [
        'job_ref',
        'project_id',
        'job_name',
        'material_id',
        'project_material_id',
        'qty_requested',
        'recipient_name',
        'qty_fulfilled',
        'status',
        'requested_by',
        'fulfilled_by',
        'fulfilled_at',
        'notes',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    // Boards that were assigned to fulfil this request
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class, 'assigned_job_ref', 'job_ref')
            ->where('status', '!=', 'Scrapped');
    }

    public function isPending(): bool    { return $this->status === 'pending';   }
    public function isFulfilled(): bool  { return $this->status === 'fulfilled'; }
}
