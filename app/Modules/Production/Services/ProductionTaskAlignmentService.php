<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderTask;
use App\Modules\Production\Models\WorkOrderMidQcCheck;
use App\Modules\Production\Models\ProductionNcr;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\ProjectEnquiry;
use App\Models\TaskProcurementData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionTaskAlignmentService
{
    /**
     * Get aligned production data for a project task
     */
    public function getAlignmentData(int $taskId): array
    {
        $task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
        $enquiry = $task->enquiry;

        // Find or create WorkOrder for this task
        $workOrder = WorkOrder::where('enquiry_task_id', $taskId)->first();
        
        if (!$workOrder) {
            $workOrder = $this->initializeWorkOrder($task);
        }

        // Fetch related data from Production Module with deep relations
        $workOrder->load([
            'tasks.assignees.user',
            'tasks.evidence',
            'midQcChecks.checkedBy',
            'finalQcChecks.checkedBy',
            'ncrs',
            'reworks',
            'scrapLogs',
            'dailyTasks.jobCard.worker'
        ]);

        // Get related procurement items
        $relatedProcurementItems = $this->getRelatedProcurementItems($enquiry->id);

        // Calculate Execution Health
        $totalWorkTasks = $workOrder->tasks->count();
        $completedWorkTasks = $workOrder->tasks->where('status', 'completed')->count();
        $progress = $totalWorkTasks > 0 ? round(($completedWorkTasks / $totalWorkTasks) * 100) : 0;

        $totalQc = $workOrder->midQcChecks->count() + $workOrder->finalQcChecks->count();
        $passedQc = $workOrder->midQcChecks->where('status', 'passed')->count() + 
                    $workOrder->finalQcChecks->where('status', 'passed')->count();
        $qcPassRate = $totalQc > 0 ? round(($passedQc / $totalQc) * 100) : 100;

        $openNcrs = $workOrder->ncrs->where('status', '!=', 'closed')->count();
        $reworkHours = $workOrder->reworks->sum('hours');

        return [
            'productionData' => [
                'id' => $workOrder->id,
                'taskId' => $taskId,
                'workOrderNumber' => $workOrder->work_order_number,
                'status' => $workOrder->status,
                'workflowSteps' => $workOrder->workflow_completed_steps ?? [],
                'materialsImported' => $totalWorkTasks > 0,
                'executionHealth' => [
                    'progress' => $progress,
                    'qcPassRate' => $qcPassRate,
                    'openNcrs' => $openNcrs,
                    'reworkHours' => $reworkHours,
                    'status' => $openNcrs > 0 ? 'at_risk' : ($progress > 0 ? 'on_track' : 'pending')
                ]
            ],
            'projectInfo' => [
                'projectId' => $enquiry->job_number ?? $enquiry->enquiry_number,
                'enquiryNumber' => $enquiry->enquiry_number,
                'enquiryTitle' => $enquiry->title,
                'clientName' => $enquiry->client->full_name ?? $enquiry->contact_person,
                'eventVenue' => $enquiry->venue ?? 'TBC',
                'setupDate' => $enquiry->expected_delivery_date,
                'setDownDate' => $enquiry->set_down_date,
                'contactPerson' => $enquiry->contact_person,
            ],
            'relatedProcurementItems' => $relatedProcurementItems,
            
            'productionElements' => $workOrder->tasks->map(function ($woTask) {
                return [
                    'id' => (string)$woTask->id,
                    'name' => $woTask->title,
                    'category' => $woTask->workstation ?? 'general_assembly',
                    'quantity' => (float)$woTask->quantity,
                    'unit' => 'pcs',
                    'status' => $woTask->status,
                    'notes' => $woTask->notes,
                    'specifications' => $woTask->description,
                    'assignees' => $woTask->assignees->pluck('user.name'),
                    'evidenceCount' => $woTask->evidence->count()
                ];
            }),

            'qualityControl' => [
                'midStream' => $workOrder->midQcChecks->map(function ($qc) {
                    return [
                        'id' => (string)$qc->id,
                        'title' => $qc->title ?? $qc->workstation,
                        'status' => $qc->status,
                        'checkedBy' => $qc->checkedBy->name ?? 'Pending',
                        'checkedAt' => $qc->checked_at?->toIso8601String(),
                        'notes' => $qc->notes
                    ];
                }),
                'final' => $workOrder->finalQcChecks->map(function ($qc) {
                    return [
                        'id' => (string)$qc->id,
                        'title' => $qc->title ?? 'Final Inspection',
                        'status' => $qc->status,
                        'checkedBy' => $qc->checkedBy->name ?? 'Pending',
                        'checkedAt' => $qc->checked_at?->toIso8601String(),
                        'notes' => $qc->notes
                    ];
                })
            ],

            'issues' => $workOrder->ncrs->map(function ($ncr) {
                return [
                    'id' => (string)$ncr->id,
                    'type' => 'ncr',
                    'title' => "NCR: {$ncr->ncr_number}",
                    'description' => $ncr->description,
                    'status' => $ncr->status === 'closed' ? 'resolved' : 'open',
                    'priority' => $ncr->severity ?? 'medium',
                    'reportedBy' => 'Quality Inspector',
                    'reportedDate' => $ncr->created_at->toIso8601String(),
                ];
            }),

            'reworkAnalytics' => $workOrder->reworks->map(function ($rework) {
                return [
                    'id' => (string)$rework->id,
                    'title' => $rework->reason,
                    'hours' => (float)$rework->hours,
                    'status' => $rework->status,
                    'date' => $rework->created_at->toIso8601String()
                ];
            }),

            'evidenceGallery' => $workOrder->tasks->flatMap(function ($task) {
                return $task->evidence->map(function ($ev) use ($task) {
                    return [
                        'id' => (string)$ev->id,
                        'taskTitle' => $task->title,
                        'url' => $ev->file_path,
                        'type' => $ev->file_type,
                        'uploadedAt' => $ev->created_at->toIso8601String(),
                        'notes' => $ev->notes
                    ];
                });
            })->values(),

            'productionLogs' => ($workOrder->dailyTasks ?? collect())->map(function ($log) {
                $jobCard = $log->jobCard;
                $worker = $jobCard ? $jobCard->worker_data : null;
                
                return [
                    'id' => (string)$log->id,
                    'date' => $jobCard?->date?->toIso8601String(),
                    'workerName' => $worker ? ($worker['first_name'] . ' ' . $worker['last_name']) : 'System',
                    'description' => $log->description,
                    'hoursWorked' => (float)$log->hours_worked,
                    'status' => $jobCard->status ?? 'submitted'
                ];
            }),

            'completionCriteria' => []
        ];
    }

    /**
     * Sync materials from project task to production work order tasks
     */
    public function syncMaterials(int $taskId): bool
    {
        DB::beginTransaction();
        try {
            $task = EnquiryTask::findOrFail($taskId);
            $workOrder = WorkOrder::where('enquiry_task_id', $taskId)->firstOrFail();

            // Find the materials task for this enquiry
            $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'materials')
                ->first();

            if (!$materialsTask) {
                throw new \Exception('Materials task not found for this enquiry');
            }

            // Get materials data
            $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData || !$materialsData->elements || $materialsData->elements->isEmpty()) {
                throw new \Exception('No materials data found. Please complete the Materials Task first.');
            }

            // Clear existing tasks that aren't in progress or completed to avoid duplicates
            WorkOrderTask::where('work_order_id', $workOrder->id)
                ->where('status', 'pending')
                ->delete();

            foreach ($materialsData->elements as $element) {
                // Determine workstation based on element name
                $workstation = $this->determineWorkstation($element->name ?? '');
                
                WorkOrderTask::create([
                    'work_order_id' => $workOrder->id,
                    'workstation' => $workstation,
                    'title' => $element->name ?? 'Unnamed Element',
                    'quantity' => 1,
                    'priority' => $workOrder->priority,
                    'status' => 'pending',
                    'included' => true,
                    'notes' => "Materials: " . ($element->materials->pluck('description')->implode(', ') ?: 'N/A'),
                    'created_by' => auth()->id() ?? 1
                ]);
            }
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to sync materials to WorkOrder: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate quality checkpoints for a task based on its WorkOrderTasks
     */
    public function generateQualityCheckpoints(int $taskId): array
    {
        $workOrder = WorkOrder::where('enquiry_task_id', $taskId)->firstOrFail();
        $workOrder->load('tasks');

        DB::beginTransaction();
        try {
            foreach ($workOrder->tasks as $task) {
                // Create a MidQcCheck for each workstation task if it doesn't exist
                WorkOrderMidQcCheck::firstOrCreate(
                    [
                        'work_order_id' => $workOrder->id,
                        'title' => "QA: {$task->title} ({$task->workstation})",
                    ],
                    [
                        'status' => 'pending',
                        'created_by' => auth()->id() ?? 1
                    ]
                );
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to generate QC checkpoints: " . $e->getMessage());
        }

        return $this->getAlignmentData($taskId)['qualityControl'];
    }

    /**
     * Save data back to production module
     */
    public function saveAlignmentData(int $taskId, array $data): bool
    {
        try {
            $workOrder = WorkOrder::where('enquiry_task_id', $taskId)->firstOrFail();
            
            if (isset($data['productionData']['status'])) {
                $workOrder->update(['status' => $data['productionData']['status']]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to save alignment data: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Batch create work orders for existing projects
     */
    public function createWorkOrdersForExistingProjects(): array
    {
        $results = ['created' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $enquiriesWithoutWorkOrders = ProjectEnquiry::whereNotIn('id', function ($query) {
                $query->select('project_enquiry_id')->from('work_orders')->whereNotNull('project_enquiry_id');
            })
            ->whereIn('status', ['approved', 'project_mobilization', 'in_production'])
            ->get();

            foreach ($enquiriesWithoutWorkOrders as $enquiry) {
                try {
                    $workOrderNumber = $this->generateWorkOrderNumberForEnquiry($enquiry);

                    WorkOrder::create([
                        'work_order_number' => $workOrderNumber,
                        'project_enquiry_id' => $enquiry->id,
                        'title' => $enquiry->title,
                        'specifications' => $enquiry->description,
                        'quantity' => 1,
                        'status' => 'pending',
                        'priority' => $this->mapPriority($enquiry->priority ?? 'medium'),
                        'due_date' => $enquiry->expected_delivery_date,
                        'assigned_to' => $enquiry->project_officer_id,
                        'created_by' => $enquiry->created_by ?? 1,
                    ]);

                    $results['created']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "Failed for enquiry {$enquiry->id}: " . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            $results['errors'][] = "Process failed: " . $e->getMessage();
        }

        return $results;
    }

    private function generateWorkOrderNumberForEnquiry(ProjectEnquiry $enquiry): string
    {
        $year = date('Y');
        $month = date('m');
        $sequence = WorkOrder::whereYear('created_at', $year)->whereMonth('created_at', $month)->count() + 1;
        return sprintf('WO-%s%s-%03d', $year, $month, $sequence);
    }

    private function mapPriority(string $enquiryPriority): string
    {
        $map = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'urgent' => 'urgent'];
        return $map[strtolower($enquiryPriority)] ?? 'medium';
    }

    private function determineWorkstation(string $name): string
    {
        $name = strtolower($name);
        if (str_contains($name, 'stage')) return 'carpentry';
        if (str_contains($name, 'backdrop') || str_contains($name, 'print')) return 'branding';
        if (str_contains($name, 'arc') || str_contains($name, 'metal')) return 'metal_work';
        if (str_contains($name, 'paint')) return 'painting';
        return 'general_assembly';
    }

    private function initializeWorkOrder(EnquiryTask $task): WorkOrder
    {
        $enquiry = $task->enquiry;
        return WorkOrder::create([
            'enquiry_task_id' => $task->id,
            'project_enquiry_id' => $enquiry->id,
            'title' => $enquiry->title,
            'work_order_number' => 'WO-' . strtoupper(uniqid()),
            'status' => 'pending',
            'priority' => 'medium',
            'quantity' => 1,
            'created_by' => auth()->id() ?? 1
        ]);
    }

    private function getRelatedProcurementItems(int $enquiryId): array
    {
        try {
            $procurementTask = EnquiryTask::where('project_enquiry_id', $enquiryId)
                ->where('type', 'procurement')
                ->first();
            
            if ($procurementTask) {
                $procData = TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->first();
                return $procData ? ($procData->procurement_items ?? []) : [];
            }
        } catch (\Exception $e) {
            Log::warning('ProductionTaskAlignmentService: Failed to fetch procurement data');
        }
        return [];
    }
}
