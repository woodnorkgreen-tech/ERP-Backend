<?php

namespace App\Modules\logisticsTask\Services;

use App\Modules\logisticsTask\Models\LogisticsTask;
use App\Modules\logisticsTask\Models\TransportItem;
use App\Modules\logisticsTask\Models\LogisticsChecklist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class LogisticsTaskService
{
    /**
     * Get logistics data for a specific task
     */
    public function getLogisticsForTask(int $taskId): ?array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)
            ->with(['transportItems', 'checklist.checklistItems', 'team.category', 'team.teamType'])
            ->first();

        if (!$logisticsTask) {
            return null; // Return null when no logistics data exists
        }

        return [
            'id' => $logisticsTask->id,
            'task_id' => $logisticsTask->task_id,
            'team' => $logisticsTask->team ? [
                'id' => $logisticsTask->team->id,
                'category' => $logisticsTask->team->category->name ?? null,
                'team_type' => $logisticsTask->team->teamType->name ?? null,
                'status' => $logisticsTask->team->status,
                'required_members' => $logisticsTask->team->required_members,
                'assigned_members_count' => $logisticsTask->team->assigned_members_count,
            ] : null,
            'logistics_planning' => $logisticsTask->logistics_planning ?? [],
            'team_confirmation' => [
                'setup_teams_confirmed' => $logisticsTask->setup_teams_confirmed,
                'notes' => $logisticsTask->team_confirmation_notes,
            ],
            'transport_items' => $logisticsTask->transportItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'category' => $item->category,
                    'source' => $item->source,
                    'weight' => $item->weight,
                    'special_handling' => $item->special_handling,
                ];
            })->toArray(),
            'checklist' => $this->formatChecklistData($logisticsTask->checklist->first()),
            'status' => $logisticsTask->status,
        ];
    }

    /**
     * Save logistics planning data
     */
    public function saveLogisticsPlanning(int $taskId, array $data): LogisticsTask
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $logisticsTask->update([
                'logistics_planning' => $data,
                'updated_by' => auth()->id(),
            ]);

            return $logisticsTask->fresh();
        });
    }

    /**
     * Update team confirmation
     */
    public function updateTeamConfirmation(int $taskId, array $data): LogisticsTask
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $logisticsTask->update([
                'setup_teams_confirmed' => $data['setup_teams_confirmed'] ?? false,
                'team_confirmation_notes' => $data['notes'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            return $logisticsTask->fresh();
        });
    }

    /**
     * Get transport items for a task
     */
    public function getTransportItems(int $taskId): array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)->first();

        if (!$logisticsTask) {
            return [];
        }

        return $logisticsTask->transportItems->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'category' => $item->category,
                'source' => $item->source,
                'weight' => $item->weight,
                'special_handling' => $item->special_handling,
            ];
        })->toArray();
    }

    /**
     * Add a transport item
     */
    public function addTransportItem(int $taskId, array $data): TransportItem
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            return $logisticsTask->transportItems()->create([
                ...$data,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Update a transport item
     */
    public function updateTransportItem(int $itemId, array $data): TransportItem
    {
        $item = TransportItem::findOrFail($itemId);
        $item->update($data);
        return $item->fresh();
    }

    /**
     * Remove a transport item
     */
    public function removeTransportItem(int $itemId): bool
    {
        $item = TransportItem::findOrFail($itemId);
        return $item->delete();
    }

    /**
     * Import production elements as transport items
     */
    public function importProductionElements(int $taskId): array
    {
        // This would integrate with production/budget tasks
        // For now, return empty array
        return [];
    }

    /**
     * Get checklist for a task
     */
    public function getChecklistForTask(int $taskId): array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)->first();

        if (!$logisticsTask || !$logisticsTask->checklist) {
            return $this->getEmptyChecklistStructure();
        }

        return $this->formatChecklistData($logisticsTask->checklist);
    }

    /**
     * Update checklist data
     */
    public function updateChecklist(int $taskId, array $data): LogisticsChecklist
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $checklist = $logisticsTask->checklist()->firstOrCreate(
                ['logistics_task_id' => $logisticsTask->id],
                ['created_by' => auth()->id()]
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
    public function generateChecklistFromItems(int $taskId): array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)->first();

        if (!$logisticsTask) {
            return $this->getEmptyChecklistStructure();
        }

        $items = $logisticsTask->transportItems->map(function ($item) {
            return [
                'id' => 'item_' . $item->id,
                'item_name' => $item->name,
                'status' => 'missing',
                'notes' => null,
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
        ];

        return $checklistData;
    }

    /**
     * Get project ID from task ID
     */
    private function getProjectIdFromTask(int $taskId): ?int
    {
        // Return null to avoid foreign key constraint issues
        // Project ID can be set later when project is created
        return null;
    }

    /**
     * Get empty logistics structure
     */
    private function getEmptyLogisticsStructure(int $taskId): array
    {
        return [
            'task_id' => $taskId,
            'logistics_planning' => [],
            'team_confirmation' => [
                'setup_teams_confirmed' => false,
                'notes' => null,
            ],
            'transport_items' => [],
            'checklist' => $this->getEmptyChecklistStructure(),
            'status' => 'pending',
        ];
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
        ];
    }

    /**
     * Format checklist data for API response
     */
    private function formatChecklistData(?LogisticsChecklist $checklist): array
    {
        if (!$checklist) {
            return $this->getEmptyChecklistStructure();
        }

        return $checklist->checklist_data ?? $this->getEmptyChecklistStructure();
    }
}
