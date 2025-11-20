<?php

namespace App\Modules\logisticsTask\Services;

use App\Modules\logisticsTask\Models\LogisticsChecklist;
use App\Modules\logisticsTask\Models\LogisticsChecklistItem;
use App\Modules\logisticsTask\Models\LogisticsTask;
use Illuminate\Support\Facades\DB;

class LogisticsChecklistService
{
    /**
     * Get checklist for a logistics task
     */
    public function getChecklist(int $logisticsTaskId): array
    {
        $checklist = LogisticsChecklist::where('logistics_task_id', $logisticsTaskId)
            ->with('checklistItems')
            ->first();

        if (!$checklist) {
            return $this->getEmptyChecklistStructure();
        }

        return [
            'id' => $checklist->id,
            'logistics_task_id' => $checklist->logistics_task_id,
            'data' => $checklist->checklist_data ?? $this->getEmptyChecklistStructure(),
            'completion_percentage' => $checklist->completion_percentage,
            'created_at' => $checklist->created_at,
            'updated_at' => $checklist->updated_at,
        ];
    }

    /**
     * Create or update checklist
     */
    public function saveChecklist(int $logisticsTaskId, array $data): LogisticsChecklist
    {
        return DB::transaction(function () use ($logisticsTaskId, $data) {
            // Validate logistics task exists
            LogisticsTask::findOrFail($logisticsTaskId);

            $checklist = LogisticsChecklist::firstOrCreate(
                ['logistics_task_id' => $logisticsTaskId],
                [
                    'checklist_data' => $this->getEmptyChecklistStructure(),
                    'created_by' => auth()->id(),
                ]
            );

            $checklist->update([
                'checklist_data' => $data,
                'updated_by' => auth()->id(),
            ]);

            return $checklist->fresh();
        });
    }

    /**
     * Generate checklist from transport items
     */
    public function generateFromTransportItems(int $logisticsTaskId): array
    {
        $logisticsTask = LogisticsTask::with('transportItems')->findOrFail($logisticsTaskId);

        $items = $logisticsTask->transportItems->map(function ($item) {
            return [
                'id' => 'item_' . $item->id,
                'item_name' => $item->name,
                'status' => 'missing',
                'notes' => null,
                'checked_at' => null,
                'checked_by' => null,
            ];
        })->toArray();

        $checklistData = [
            'items' => $items,
            'teams' => [
                'workshop' => false,
                'setup' => false,
                'setdown' => false,
            ],
            'safety' => [
                'ppe' => false,
                'first_aid' => false,
                'fire_extinguisher' => false,
            ],
            'equipment' => [
                'tools' => false,
                'vehicles' => false,
                'communication' => false,
            ],
        ];

        return $checklistData;
    }

    /**
     * Update checklist item status
     */
    public function updateChecklistItem(int $checklistId, string $itemId, array $data): LogisticsChecklistItem
    {
        return DB::transaction(function () use ($checklistId, $itemId, $data) {
            $checklist = LogisticsChecklist::findOrFail($checklistId);

            $item = LogisticsChecklistItem::firstOrCreate(
                [
                    'logistics_checklist_id' => $checklistId,
                    'item_id' => $itemId,
                ],
                [
                    'item_name' => $data['item_name'] ?? 'Unknown Item',
                    'status' => 'missing',
                ]
            );

            $item->update([
                'status' => $data['status'] ?? $item->status,
                'notes' => $data['notes'] ?? $item->notes,
                'checked_at' => now(),
                'checked_by' => auth()->id(),
            ]);

            return $item->fresh();
        });
    }

    /**
     * Bulk update checklist items
     */
    public function bulkUpdateChecklistItems(int $checklistId, array $items): array
    {
        return DB::transaction(function () use ($checklistId, $items) {
            $updatedItems = [];

            foreach ($items as $itemData) {
                $item = $this->updateChecklistItem(
                    $checklistId,
                    $itemData['id'],
                    $itemData
                );
                $updatedItems[] = $item;
            }

            return $updatedItems;
        });
    }

    /**
     * Get checklist completion statistics
     */
    public function getChecklistStats(int $logisticsTaskId): array
    {
        $checklist = LogisticsChecklist::where('logistics_task_id', $logisticsTaskId)->first();

        if (!$checklist) {
            return [
                'total_items' => 0,
                'completed_items' => 0,
                'completion_percentage' => 0,
                'missing_items' => 0,
                'present_items' => 0,
                'coming_later_items' => 0,
            ];
        }

        $data = $checklist->checklist_data ?? $this->getEmptyChecklistStructure();

        $totalItems = count($data['items'] ?? []);
        $presentItems = collect($data['items'] ?? [])->filter(function ($item) {
            return ($item['status'] ?? 'missing') === 'present';
        })->count();

        $missingItems = collect($data['items'] ?? [])->filter(function ($item) {
            return ($item['status'] ?? 'missing') === 'missing';
        })->count();

        $comingLaterItems = collect($data['items'] ?? [])->filter(function ($item) {
            return ($item['status'] ?? 'missing') === 'coming_later';
        })->count();

        return [
            'total_items' => $totalItems,
            'completed_items' => $presentItems,
            'completion_percentage' => $totalItems > 0 ? round(($presentItems / $totalItems) * 100, 1) : 0,
            'missing_items' => $missingItems,
            'present_items' => $presentItems,
            'coming_later_items' => $comingLaterItems,
        ];
    }

    /**
     * Reset checklist to empty state
     */
    public function resetChecklist(int $logisticsTaskId): LogisticsChecklist
    {
        return DB::transaction(function () use ($logisticsTaskId) {
            $checklist = LogisticsChecklist::where('logistics_task_id', $logisticsTaskId)->first();

            if ($checklist) {
                $checklist->update([
                    'checklist_data' => $this->getEmptyChecklistStructure(),
                    'updated_by' => auth()->id(),
                ]);

                // Delete all checklist items
                $checklist->checklistItems()->delete();

                return $checklist->fresh();
            }

            // Create new empty checklist
            return LogisticsChecklist::create([
                'logistics_task_id' => $logisticsTaskId,
                'checklist_data' => $this->getEmptyChecklistStructure(),
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Get empty checklist structure
     */
    private function getEmptyChecklistStructure(): array
    {
        return [
            'items' => [],
            'teams' => [
                'workshop' => false,
                'setup' => false,
                'setdown' => false,
            ],
            'safety' => [
                'ppe' => false,
                'first_aid' => false,
                'fire_extinguisher' => false,
            ],
            'equipment' => [
                'tools' => false,
                'vehicles' => false,
                'communication' => false,
            ],
        ];
    }
}
