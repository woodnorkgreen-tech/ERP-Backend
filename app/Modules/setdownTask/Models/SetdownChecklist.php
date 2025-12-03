<?php

namespace App\Modules\setdownTask\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetdownChecklist extends Model
{
    protected $fillable = [
        'setdown_task_id',
        'checklist_data',
        'completed_count',
        'total_count',
        'completion_percentage',
        'completed_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'checklist_data' => 'array',
        'completion_percentage' => 'decimal:2',
        'completed_at' => 'datetime'
    ];

    /**
     * Get the setdown task this checklist belongs to
     */
    public function setdownTask(): BelongsTo
    {
        return $this->belongsTo(SetdownTask::class);
    }

    /**
     * Get the user who created this checklist
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Get the user who last updated this checklist
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * Get default checklist structure
     */
    public static function getDefaultChecklistData(): array
    {
        return [
            [
                'category' => 'Event Details',
                'items' => [
                    ['id' => 1, 'text' => 'Venue briefing & role assignment completed', 'completed' => false],
                    ['id' => 2, 'text' => 'Team assignments and responsibilities confirmed', 'completed' => false]
                ]
            ],
            [
                'category' => 'Theme Briefing & Role Assignment',
                'items' => [
                    ['id' => 3, 'text' => 'Equipment dismantling order confirmed', 'completed' => false],
                    ['id' => 4, 'text' => 'Safety procedures and loading plan reviewed', 'completed' => false]
                ]
            ],
            [
                'category' => 'Dismantling & Sorting',
                'items' => [
                    ['id' => 5, 'text' => 'Inventory / AV equipment removal checked', 'completed' => false],
                    ['id' => 6, 'text' => 'Furniture / Setup items dismantled', 'completed' => false],
                    ['id' => 7, 'text' => 'Waste collected and disposed properly', 'completed' => false],
                    ['id' => 8, 'text' => 'Branding elements accounted for', 'completed' => false]
                ]
            ],
            [
                'category' => 'Loading & Transport',
                'items' => [
                    ['id' => 9, 'text' => 'Vehicle loading supervised by Transport Lead', 'completed' => false],
                    ['id' => 10, 'text' => 'Fragile items securely placed and cushioned', 'completed' => false],
                    ['id' => 11, 'text' => 'Inventory list matched during loading', 'completed' => false],
                    ['id' => 12, 'text' => 'Driver sign-off after final check', 'completed' => false]
                ]
            ],
            [
                'category' => 'Final Site Sweep & Departure',
                'items' => [
                    ['id' => 13, 'text' => 'Site cleaned and cleared of all WNC materials', 'completed' => false],
                    ['id' => 14, 'text' => 'Departure time recorded', 'completed' => false]
                ]
            ]
        ];
    }

    /**
     * Update checklist item completion status
     */
    public function updateItem(int $itemId, bool $completed): bool
    {
        $checklistData = $this->checklist_data;
        $updated = false;

        foreach ($checklistData as &$category) {
            foreach ($category['items'] as &$item) {
                if ($item['id'] === $itemId) {
                    $item['completed'] = $completed;
                    $updated = true;
                    break 2;
                }
            }
        }

        if ($updated) {
            $this->checklist_data = $checklistData;
            $this->calculateProgress();
            return $this->save();
        }

        return false;
    }

    /**
     * Calculate and update progress
     */
    public function calculateProgress(): void
    {
        $completedCount = 0;
        $totalCount = 0;

        foreach ($this->checklist_data as $category) {
            foreach ($category['items'] as $item) {
                $totalCount++;
                if ($item['completed']) {
                    $completedCount++;
                }
            }
        }

        $this->completed_count = $completedCount;
        $this->total_count = $totalCount;
        $this->completion_percentage = $totalCount > 0 ? ($completedCount / $totalCount) * 100 : 0;

        // Mark as completed if all items are done
        if ($completedCount === $totalCount && $totalCount > 0 && !$this->completed_at) {
            $this->completed_at = now();
        } elseif ($completedCount < $totalCount) {
            $this->completed_at = null;
        }
    }
}
