<?php

/**
 * Examples of using WorkOrder status logic that mirrors ProjectEnquiry logic
 */

// Get all work orders in different categories
$inProgressWorkOrders = \App\Modules\Production\Models\WorkOrder::inProgress()->get();
$activeWorkOrders = \App\Modules\Production\Models\WorkOrder::active()->get();
$completedWorkOrders = \App\Modules\Production\Models\WorkOrder::completed()->get();

// Check individual work order status
$workOrder = \App\Modules\Production\Models\WorkOrder::find(1);

if ($workOrder->isInProgress()) {
    echo "Work Order is IN PROGRESS (related enquiry has no job number)\n";
}

if ($workOrder->isActive()) {
    echo "Work Order is ACTIVE (related enquiry has job number and is active)\n";
}

if ($workOrder->isCompleted()) {
    echo "Work Order is COMPLETED (related enquiry is completed)\n";
}

// Get the status category directly
$category = $workOrder->getStatusCategory(); // Returns: 'in_progress', 'active', or 'completed'
echo "Work Order Status Category: {$category}\n";

// Example queries for different scenarios:

// 1. Get work orders for enquiries that are still being planned (no job number yet)
$planningWorkOrders = \App\Modules\Production\Models\WorkOrder::inProgress()
    ->with('projectEnquiry')
    ->get();

// 2. Get work orders for approved/active projects (have job number)
$activeProjectWorkOrders = \App\Modules\Production\Models\WorkOrder::active()
    ->with('projectEnquiry')
    ->get();

// 3. Get work orders for completed projects
$completedProjectWorkOrders = \App\Modules\Production\Models\WorkOrder::completed()
    ->with('projectEnquiry')
    ->get();

// 4. Get counts for dashboard
$dashboardStats = [
    'in_progress' => \App\Modules\Production\Models\WorkOrder::inProgress()->count(),
    'active' => \App\Modules\Production\Models\WorkOrder::active()->count(),
    'completed' => \App\Modules\Production\Models\WorkOrder::completed()->count(),
];

echo "Dashboard Stats:\n";
print_r($dashboardStats);

/**
 * Logic Explanation:
 * 
 * IN PROGRESS: Work orders linked to enquiries WITHOUT job_number
 * - These are enquiries still in planning/design phase
 * - Quote not yet approved, no formal project started
 * 
 * ACTIVE: Work orders linked to enquiries WITH job_number AND status is active
 * - These are approved projects that are actively being worked on
 * - Quote approved, job number assigned, project in execution
 * 
 * COMPLETED: Work orders linked to enquiries with STATUS_COMPLETED
 * - These are finished projects
 * - All work done, project delivered and marked complete
 */
