<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\JobCard;
use App\Modules\Production\Models\DailyTask;
use App\Modules\Production\Models\DailyIssue;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobCardController extends Controller
{
    /**
     * Display a listing of job cards.
     */
    public function index(Request $request): JsonResponse
    {
        $query = JobCard::with(['worker', 'tasks.workOrder', 'issues', 'approver'])
            ->withCount(['tasks', 'issues']);

        // Search by worker name or job card details
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->whereHas('worker', function($workerQuery) use ($searchTerm) {
                    $workerQuery->where('full_name', 'LIKE', "%{$searchTerm}%");
                })
                ->orWhere('notes', 'LIKE', "%{$searchTerm}%")
                ->orWhereHas('tasks', function($taskQuery) use ($searchTerm) {
                    $taskQuery->where('description', 'LIKE', "%{$searchTerm}%");
                });
            });
        }

        // Filter by worker
        if ($request->filled('worker_id')) {
            $query->where('worker_id', $request->worker_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $jobCards = $query->orderBy('date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $jobCards
        ]);
    }

    /**
     * Store a newly created job card.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => 'required|integer', // Simple integer for technical labour
            'date' => 'required|date',
            'clock_in_time' => 'nullable|string',
            'clock_out_time' => 'nullable|string',
            'notes' => 'nullable|string',
            'tasks' => 'nullable|array',
            'tasks.*.description' => 'required|string',
            'tasks.*.work_order_id' => 'nullable|integer|exists:work_orders,id',
            'tasks.*.start_time' => 'nullable|string',
            'tasks.*.end_time' => 'nullable|string',
            'tasks.*.hours_worked' => 'nullable|numeric|min:0',
            'tasks.*.notes' => 'nullable|string',
            'issues' => 'nullable|array',
            'issues.*.description' => 'required|string',
            'issues.*.resolution' => 'nullable|string',
            'issues.*.status' => 'required|in:open,resolved,escalated,under_review',
        ]);

        // Custom validation for overnight shifts
        if (isset($validated['clock_in_time']) && isset($validated['clock_out_time'])) {
            $clockIn = \Carbon\Carbon::createFromFormat('H:i', $validated['clock_in_time']);
            $clockOut = \Carbon\Carbon::createFromFormat('H:i', $validated['clock_out_time']);
            
            // Calculate total hours worked (accounting for overnight)
            $hoursWorked = $clockOut->diffInHours($clockIn);
            if ($clockOut->lt($clockIn)) {
                // Overnight shift - add 24 hours
                $hoursWorked += 24;
            }
            
            if ($hoursWorked > 24) {
                throw new \Illuminate\Validation\ValidationException(
                    validator()->make([], []),
                    ['clock_out_time' => ['Total hours worked cannot exceed 24 hours.']]
                );
            }
        }

        // Custom validation for task times
        if (isset($validated['tasks']) && is_array($validated['tasks'])) {
            foreach ($validated['tasks'] as $index => $task) {
                if (isset($task['start_time']) && isset($task['end_time'])) {
                    $start = \Carbon\Carbon::createFromFormat('H:i', $task['start_time']);
                    $end = \Carbon\Carbon::createFromFormat('H:i', $task['end_time']);
                    
                    // Calculate total hours worked (accounting for overnight)
                    $hoursWorked = $end->diffInHours($start);
                    if ($end->lt($start)) {
                        // Overnight shift - add 24 hours
                        $hoursWorked += 24;
                    }
                    
                    if ($hoursWorked > 24) {
                        throw new \Illuminate\Validation\ValidationException(
                            validator()->make([], []),
                            ["tasks.{$index}.end_time" => ['Task duration cannot exceed 24 hours.']]
                        );
                    }
                }
            }
        }

        DB::beginTransaction();
        try {
            // Set default status to pending_approval
            $validated['status'] = 'pending_approval';
            
            $jobCard = JobCard::create($validated);

            // Save tasks if provided
            if (isset($validated['tasks']) && is_array($validated['tasks'])) {
                foreach ($validated['tasks'] as $taskData) {
                    $taskData['job_card_id'] = $jobCard->id;
                    DailyTask::create($taskData);
                }
            }

            // Save issues if provided
            if (isset($validated['issues']) && is_array($validated['issues'])) {
                foreach ($validated['issues'] as $issueData) {
                    $issueData['job_card_id'] = $jobCard->id;
                    DailyIssue::create($issueData);
                }
            }

            // Calculate total hours if both times are provided
            if (isset($validated['clock_in_time']) && isset($validated['clock_out_time'])) {
                $this->calculateHours($jobCard);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Job card created successfully',
                'data' => $jobCard->load(['worker', 'tasks', 'issues'])
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . json_encode($e->errors()),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create job card: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified job card.
     */
    public function show(JobCard $jobCard): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $jobCard->load(['worker', 'tasks.workOrder', 'issues', 'approver'])
        ]);
    }

    /**
     * Public: Lookup or create a job card for a worker on a date.
     */
    public function publicLookupOrCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => 'required|integer',
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $jobCard = JobCard::firstOrCreate(
            [
                'worker_id' => $validated['worker_id'],
                'date' => $date,
            ],
            [
                'status' => 'pending_approval',
                // Avoid insert failures if DB columns are NOT NULL without defaults
                'clock_in_time' => '00:00',
                'clock_out_time' => '00:00',
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $jobCard->load(['worker', 'tasks.workOrder', 'issues', 'approver'])
        ]);
    }

    /**
     * Public: Show a job card by public token.
     */
    public function publicShow(string $token): JsonResponse
    {
        $jobCard = JobCard::where('public_token', $token)->first();

        if (!$jobCard) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid job card link'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $jobCard->load(['worker', 'tasks.workOrder', 'issues'])
        ]);
    }

    /**
     * Public: Create a new job card (same as store).
     */
    public function publicStore(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * Public: Update a job card by public token.
     */
    public function publicUpdate(Request $request, string $token): JsonResponse
    {
        $jobCard = JobCard::where('public_token', $token)->first();

        if (!$jobCard) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid job card link'
            ], 404);
        }

        $validated = $request->validate([
            'worker_id' => 'required|integer',
            'date' => 'required|date',
            'clock_in_time' => 'nullable|string',
            'clock_out_time' => 'nullable|string',
            'notes' => 'nullable|string',
            'tasks' => 'nullable|array',
            'tasks.*.id' => 'nullable|integer',
            'tasks.*.description' => 'required|string',
            'tasks.*.work_order_id' => 'nullable|integer|exists:work_orders,id',
            'tasks.*.start_time' => 'nullable|string',
            'tasks.*.end_time' => 'nullable|string',
            'tasks.*.hours_worked' => 'nullable|numeric|min:0',
            'tasks.*.notes' => 'nullable|string',
            'issues' => 'nullable|array',
            'issues.*.description' => 'required|string',
            'issues.*.resolution' => 'nullable|string',
            'issues.*.status' => 'required|in:open,resolved,escalated,under_review',
        ]);

        DB::beginTransaction();
        try {
            $originalClockIn = $jobCard->clock_in_time;
            $originalClockOut = $jobCard->clock_out_time;

            $jobCard->update($validated);

            // Handle tasks - update existing, create new, delete removed
            if (isset($validated['tasks'])) {
                $incomingTaskIds = [];
                
                foreach ($validated['tasks'] as $taskData) {
                    if (isset($taskData['id']) && $taskData['id']) {
                        // Update existing task
                        $existingTask = $jobCard->tasks()->find($taskData['id']);
                        if ($existingTask) {
                            $existingTask->update($taskData);
                            $incomingTaskIds[] = $taskData['id'];
                        }
                    } else {
                        // Create new task
                        $taskData['job_card_id'] = $jobCard->id;
                        $newTask = DailyTask::create($taskData);
                        $incomingTaskIds[] = $newTask->id;
                    }
                }
                
                // Delete tasks that are no longer present
                $jobCard->tasks()->whereNotIn('id', $incomingTaskIds)->delete();
            }

            // Handle issues - delete existing and create new ones
            if (isset($validated['issues'])) {
                // Delete existing issues
                $jobCard->issues()->delete();
                
                // Create new issues if provided
                if (is_array($validated['issues'])) {
                    foreach ($validated['issues'] as $issueData) {
                        $issueData['job_card_id'] = $jobCard->id;
                        DailyIssue::create($issueData);
                    }
                }
            }

            // Recalculate hours only if clock times were explicitly provided and have changed
            if (isset($validated['clock_in_time']) || isset($validated['clock_out_time'])) {
                // Check if times have actually changed
                $clockInChanged = isset($validated['clock_in_time']) && $originalClockIn !== $validated['clock_in_time'];
                $clockOutChanged = isset($validated['clock_out_time']) && $originalClockOut !== $validated['clock_out_time'];
                
                if ($clockInChanged || $clockOutChanged) {
                    $this->calculateHours($jobCard);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Job card updated successfully',
                'data' => $jobCard->load(['tasks.workOrder', 'issues'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update job card: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified job card.
     */
    public function update(Request $request, JobCard $jobCard): JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => 'required|integer', // Simple integer for technical labour
            'date' => 'required|date',
            'clock_in_time' => 'nullable|string',
            'clock_out_time' => 'nullable|string',
            'notes' => 'nullable|string',
            'tasks' => 'nullable|array',
            'issues' => 'nullable|array'
        ]);

        DB::beginTransaction();
        try {
            $originalClockIn = $jobCard->clock_in_time;
            $originalClockOut = $jobCard->clock_out_time;

            $jobCard->update($validated);

            // Handle tasks - update existing, create new, delete removed
            if (isset($validated['tasks'])) {
                $incomingTaskIds = [];
                
                foreach ($validated['tasks'] as $taskData) {
                    if (isset($taskData['id']) && $taskData['id']) {
                        // Update existing task
                        $existingTask = $jobCard->tasks()->find($taskData['id']);
                        if ($existingTask) {
                            $existingTask->update($taskData);
                            $incomingTaskIds[] = $taskData['id'];
                        }
                    } else {
                        // Create new task
                        $taskData['job_card_id'] = $jobCard->id;
                        $newTask = DailyTask::create($taskData);
                        $incomingTaskIds[] = $newTask->id;
                    }
                }
                
                // Delete tasks that are no longer present
                $jobCard->tasks()->whereNotIn('id', $incomingTaskIds)->delete();
            }

            // Handle issues - delete existing and create new ones
            if (isset($validated['issues'])) {
                // Delete existing issues
                $jobCard->issues()->delete();
                
                // Create new issues if provided
                if (is_array($validated['issues'])) {
                    foreach ($validated['issues'] as $issueData) {
                        $issueData['job_card_id'] = $jobCard->id;
                        DailyIssue::create($issueData);
                    }
                }
            }

            // Recalculate hours only if clock times were explicitly provided and have changed
            if (isset($validated['clock_in_time']) || isset($validated['clock_out_time'])) {
                // Check if times have actually changed
                $clockInChanged = isset($validated['clock_in_time']) && $originalClockIn !== $validated['clock_in_time'];
                $clockOutChanged = isset($validated['clock_out_time']) && $originalClockOut !== $validated['clock_out_time'];
                
                if ($clockInChanged || $clockOutChanged) {
                    $this->calculateHours($jobCard);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Job card updated successfully',
                'data' => $jobCard->load(['tasks.workOrder', 'issues'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update job card: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified job card from storage.
     */
    public function destroy(JobCard $jobCard): JsonResponse
    {
        try {
            DB::beginTransaction();
            
            // Delete related tasks and issues first
            $jobCard->tasks()->delete();
            $jobCard->issues()->delete();
            
            // Delete the job card
            $jobCard->delete();
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Job card deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete job card: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update job card status.
     */
    public function updateStatus(Request $request, JobCard $jobCard): JsonResponse
    {
        \Log::info('updateStatus method called', [
            'job_card_id' => $jobCard->id,
            'request_method' => $request->method(),
            'request_path' => $request->path(),
            'all_request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'status' => 'required|in:pending_approval,approved,rejected',
            'notes' => 'nullable|string',
        ]);

        // Validate status transitions
        $currentStatus = $jobCard->status ?? 'pending_approval'; // Fallback to pending_approval if null
        $newStatus = $validated['status'];

        // Debug logging
        \Log::info('JobCard Status Update Attempt', [
            'job_card_id' => $jobCard->id,
            'current_status' => $currentStatus,
            'new_status' => $newStatus,
            'request_data' => $request->all()
        ]);

        // Define allowed transitions
        $allowedTransitions = [
            'pending_approval' => ['pending_approval', 'approved', 'rejected'],
            'approved' => ['approved', 'pending_approval', 'rejected'], // Can go back to pending or rejected
            'rejected' => ['pending_approval', 'approved', 'rejected'], // Can go to pending, approved, or stay rejected
        ];

        // Check if current status exists in allowed transitions
        if (!isset($allowedTransitions[$currentStatus])) {
            return response()->json([
                'success' => false,
                'message' => "Invalid current status: {$currentStatus}"
            ], 422);
        }

        if (!in_array($newStatus, $allowedTransitions[$currentStatus])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot change status from {$currentStatus} to {$newStatus}"
            ], 422);
        }

        // Handle approval/rejection specific logic
        if ($newStatus === 'approved') {
            $jobCard->update([
                'status' => $newStatus,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'notes' => $validated['notes'] ?? $jobCard->notes
            ]);
        } else if ($newStatus === 'rejected') {
            $jobCard->update([
                'status' => $newStatus,
                'notes' => ($validated['notes'] ?? null ? 'Rejected: ' . $validated['notes'] : 'Rejected') . ($jobCard->notes ? ' | ' . $jobCard->notes : '')
            ]);
        } else {
            $jobCard->update([
                'status' => $newStatus,
                'notes' => $validated['notes'] ?? $jobCard->notes
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Job card status updated to {$newStatus}",
            'data' => $jobCard->load('approver')
        ]);
    }

    /**
     * Submit job card for approval.
     */
    public function submitForApproval(JobCard $jobCard): JsonResponse
    {
        if ($jobCard->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft job cards can be submitted for approval'
            ], 422);
        }

        $jobCard->update(['status' => 'pending_approval']);

        return response()->json([
            'success' => true,
            'message' => 'Job card submitted for approval',
            'data' => $jobCard
        ]);
    }

    /**
     * Approve job card.
     */
    public function approve(Request $request, JobCard $jobCard): JsonResponse
    {
        if ($jobCard->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending approval job cards can be approved'
            ], 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $jobCard->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'notes' => $validated['notes'] ?? $jobCard->notes
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Job card approved successfully',
            'data' => $jobCard->load('approver')
        ]);
    }

    /**
     * Reject job card.
     */
    public function reject(Request $request, JobCard $jobCard): JsonResponse
    {
        if ($jobCard->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending approval job cards can be rejected'
            ], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        $jobCard->update([
            'status' => 'rejected',
            'notes' => 'Rejected: ' . $validated['rejection_reason'] . ($jobCard->notes ? ' | ' . $jobCard->notes : '')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Job card rejected',
            'data' => $jobCard
        ]);
    }

    /**
     * Get technicians for dropdown.
     * Only pulls from technical labour table.
     */
    public function technicians(Request $request): JsonResponse
    {
        $search = $request->get('q', '');

        // Get technical labour from HR module only
        $technicalLabourQuery = \App\Modules\HR\Models\TechnicalLabour::active();

        // Handle search for technical labour
        if ($search) {
            $technicalLabourQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('specialization', 'like', "%{$search}%");
            });
        }

        $technicalLabours = $technicalLabourQuery->orderBy('full_name')->get([
            'id',
            'full_name',
            'phone',
            'email',
            'specialization',
            'day_rate'
        ])->map(function ($tech) {
            $nameParts = explode(' ', $tech->full_name);
            return [
                'id' => $tech->id, // Use simple integer ID (1, 2, 3...)
                'first_name' => $nameParts[0] ?? '',
                'last_name' => implode(' ', array_slice($nameParts, 1)),
                'employee_number' => $tech->phone ?? 'TECH-' . $tech->id,
                'source' => 'technical_labour',
                'department' => 'Technical Resource Pool',
                'specialization' => $tech->specialization,
                'day_rate' => $tech->day_rate
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $technicalLabours
        ]);
    }

    /**
     * Public: Get technicians for dropdown.
     */
    public function publicTechnicians(Request $request): JsonResponse
    {
        return $this->technicians($request);
    }

    /**
     * Search work orders.
     */
    public function searchWorkOrders(Request $request): JsonResponse
    {
        $query = $request->get('q');
        
        if (!$query || strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $workOrders = WorkOrder::with(['projectEnquiry', 'assignedUser'])
            ->where(function($q) use ($query) {
                $q->where('work_order_number', 'like', "%{$query}%")
                  ->orWhere('title', 'like', "%{$query}%")
                  ->orWhere('specifications', 'like', "%{$query}%");
            })
            ->limit(20)
            ->get([
                'id', 
                'work_order_number', 
                'title', 
                'specifications',
                'status',
                'priority',
                'due_date',
                'assigned_to'
            ]);

        return response()->json([
            'success' => true,
            'data' => $workOrders
        ]);
    }

    /**
     * Calculate total and overtime hours for a job card.
     */
    private function calculateHours(JobCard $jobCard): void
    {
        if (!$jobCard->clock_in_time || !$jobCard->clock_out_time) {
            $jobCard->update([
                'total_hours' => 0,
                'overtime_hours' => 0
            ]);
            return;
        }

        // Parse time strings (supports both ISO datetime and time-only formats)
        $clockIn = \Carbon\Carbon::createFromFormat('H:i', $jobCard->clock_in_time);
        $clockOut = \Carbon\Carbon::createFromFormat('H:i', $jobCard->clock_out_time);
        
        // Handle overnight shifts - if clock out is before clock in, it's next day
        if ($clockOut->lt($clockIn)) {
            $clockOut->addDay();
        }

        $totalMinutes = $clockIn->diffInMinutes($clockOut);
        $totalHours = $totalMinutes / 60;

        // Calculate overtime (after 6 PM = 18:00)
        $overtimeStart = \Carbon\Carbon::createFromTime(18, 0, 0);
        $overtimeMinutes = 0;

        if ($clockOut->gt($overtimeStart)) {
            $overtimeStart = $overtimeStart->max($clockIn);
            $overtimeMinutes = $overtimeStart->diffInMinutes($clockOut);
        }

        $overtimeHours = $overtimeMinutes / 60;

        $jobCard->update([
            'total_hours' => $totalHours,
            'overtime_hours' => $overtimeHours
        ]);
    }
}
