<?php

namespace App\Modules\Printing\Models;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintMaterialRequest extends Model
{
    protected $fillable = [
        'material_id',
        'requested_quantity_m',
        'project_id',
        'project_enquiry_id',
        'print_job_id',
        'urgency',
        'reason',
        'status',
        'stores_inventory_log_id',
        'requested_by',
        'approved_by',
        'received_by',
    ];

    protected $casts = [
        'requested_quantity_m' => 'decimal:3',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'material_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }

    public function storesInventoryLog(): BelongsTo
    {
        return $this->belongsTo(InventoryLog::class, 'stores_inventory_log_id');
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(PrintRoll::class);
    }
}
