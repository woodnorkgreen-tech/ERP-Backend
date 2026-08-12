<?php

namespace App\Modules\Printing\Models;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintManualConsumption extends Model
{
    protected $fillable = [
        'print_roll_id',
        'material_id',
        'project_id',
        'project_enquiry_id',
        'operator_id',
        'reason',
        'quantity_m',
        'notes',
        'consumed_at',
        'created_by',
    ];

    protected $casts = [
        'quantity_m' => 'decimal:3',
        'consumed_at' => 'datetime',
    ];

    public function roll(): BelongsTo
    {
        return $this->belongsTo(PrintRoll::class, 'print_roll_id');
    }

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

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
