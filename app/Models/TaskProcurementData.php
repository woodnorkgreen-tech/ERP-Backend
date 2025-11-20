<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="TaskProcurementData",
 *     title="Task Procurement Data",
 *     description="Procurement data for a task including imported budget items and vendor assignments",
 *     @OA\Property(property="id", type="integer", description="Primary key"),
 *     @OA\Property(property="enquiry_task_id", type="integer", description="Related enquiry task ID"),
 *     @OA\Property(property="project_info", type="object", description="Project information"),
 *     @OA\Property(property="budget_imported", type="boolean", description="Whether budget has been imported"),
 *     @OA\Property(property="procurement_items", type="array", description="List of procurement items", @OA\Items(ref="#/components/schemas/ProcurementItem")),
 *     @OA\Property(property="budget_summary", type="object", description="Budget summary information"),
 *     @OA\Property(property="last_import_date", type="string", format="date-time", description="Last budget import date"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class TaskProcurementData extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        'project_info',
        'budget_imported',
        'procurement_items',
        'budget_summary',
        'last_import_date'
    ];

    protected $casts = [
        'project_info' => 'array',
        'budget_imported' => 'boolean',
        'procurement_items' => 'array',
        'budget_summary' => 'array',
        'last_import_date' => 'datetime'
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }

    public function enquiry_task(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }

    // Helper methods
    public function isBudgetImported(): bool
    {
        return $this->budget_imported;
    }

    public function getProcurementItemsCount(): int
    {
        return is_array($this->procurement_items) ? count($this->procurement_items) : 0;
    }

    public function getCompletedItemsCount(): int
    {
        if (!is_array($this->procurement_items)) {
            return 0;
        }

        return collect($this->procurement_items)->filter(function ($item) {
            return in_array($item['availability_status'] ?? '', ['received', 'hired']);
        })->count();
    }

    public function getStatusCounts(): array
    {
        if (!is_array($this->procurement_items)) {
            return [
                'available' => 0,
                'ordered' => 0,
                'received' => 0,
                'hired' => 0,
                'cancelled' => 0
            ];
        }

        $counts = collect($this->procurement_items)->countBy('availability_status');

        return [
            'available' => $counts->get('available', 0),
            'ordered' => $counts->get('ordered', 0),
            'received' => $counts->get('received', 0),
            'hired' => $counts->get('hired', 0),
            'cancelled' => $counts->get('cancelled', 0)
        ];
    }
}
