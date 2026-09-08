<?php

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintRoll extends Model
{
    protected $fillable = [
        'material_id',
        'source_inventory_log_id',
        'print_material_request_id',
        'material_code_snapshot',
        'material_name_snapshot',
        'roll_code',
        'display_label',
        'received_sequence',
        'received_at',
        'received_length_m',
        'remaining_length_m',
        'roll_width_m',
        'status',
        'location',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'received_at' => 'date',
        'received_sequence' => 'integer',
        'received_length_m' => 'decimal:3',
        'remaining_length_m' => 'decimal:3',
        'roll_width_m' => 'decimal:3',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    public function sourceInventoryLog(): BelongsTo
    {
        return $this->belongsTo(InventoryLog::class, 'source_inventory_log_id');
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(PrintMaterialRequest::class, 'print_material_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function jobConsumptions(): HasMany
    {
        return $this->hasMany(PrintJobConsumption::class);
    }

    public function manualConsumptions(): HasMany
    {
        return $this->hasMany(PrintManualConsumption::class);
    }
}
