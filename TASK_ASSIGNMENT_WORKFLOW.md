# Task Assignment Workflow Documentation

## Overview
The task assignment workflow has been redesigned to implement manual task assignment by project managers, replacing the previous automatic assignment system. This ensures better control over task distribution and accountability.

## Key Changes
1. **No Automatic Assignment**: Tasks are no longer automatically assigned to departments upon enquiry creation
2. **Manual Assignment**: Project managers manually assign tasks to users with proper validation
3. **Permission Controls**: Strict permission checks ensure only authorized users can assign/reassign tasks
4. **Validation Rules**: Comprehensive validation prevents invalid assignments
5. **Notifications**: Automated notifications for assignments and due dates
6. **Escalation Logic**: Automatic priority escalation for overdue tasks
7. **Audit Trail**: Complete assignment history tracking

## Workflow Rules

### 1. Enquiry Creation
- When an enquiry is created, only basic tasks are generated (Site Survey and Design)
- Tasks are created in `pending` status with no department or user assignment
- Tasks remain unassigned until manually assigned by a project manager

### 2. Task Assignment
- **Who can assign**: Only users with `Project Manager` role or `TASK_ASSIGN` permission
- **Validation rules**:
  - Assigned user must belong to a department
  - Cannot assign to the same user twice for the same task type in the same enquiry
  - Due dates cannot be in the past
  - Priority levels: low, medium, high, urgent

### 3. Task Reassignment
- **Who can reassign**: Same permissions as assignment
- **Process**: Creates new assignment history entry with reason
- **Notifications**: Both old and new assignees are notified

### 4. Priority Escalation
- **Automatic escalation** based on overdue duration:
  - 1+ days overdue: medium → high
  - 3+ days overdue: high → urgent
  - 7+ days overdue: urgent (maximum)
- **Notifications**: Sent to assignee and project managers

### 5. Due Date Management
- **Reminders**: Automatic reminders sent 1 day before due date
- **Overdue alerts**: Daily escalation checks for overdue tasks
- **Validation**: Due dates cannot be set in the past

### 6. Notifications
- **Task assigned**: Sent to assignee with task details
- **Task reassigned**: Sent to both old and new assignees
- **Due soon**: Reminder sent 1 day before due date
- **Overdue**: Escalation notifications for overdue tasks

## API Endpoints

### Task Management
```
GET    /api/projects/enquiries/{id}/tasks          # Get enquiry tasks
PUT    /api/projects/enquiry-tasks/{id}/assign     # Assign task
PUT    /api/projects/enquiry-tasks/{id}/reassign   # Reassign task
PUT    /api/projects/enquiry-tasks/{id}            # Update task
GET    /api/projects/enquiry-tasks/{id}/assignment-history  # Get assignment history
```

### Permissions Required
- `TASK_ASSIGN`: Required for assignment and reassignment operations
- `TASK_UPDATE`: Required for task updates
- `TASK_READ`: Required for viewing tasks and history

## Database Schema

### enquiry_tasks Table
- `project_enquiry_id`: Foreign key to enquiries
- `department_id`: Assigned department (nullable)
- `title`: Task title
- `type`: Task type (survey, design, materials, budget, quote)
- `status`: Task status (pending, in_progress, completed)
- `priority`: Task priority (low, medium, high, urgent)
- `due_date`: Task due date (nullable)
- `assigned_by`: User who assigned the task
- `assigned_at`: Assignment timestamp
- `notes`: Assignment notes

### task_assignment_history Table
- `enquiry_task_id`: Foreign key to tasks
- `assigned_to`: User assigned to
- `assigned_by`: User who made assignment
- `assigned_at`: Assignment timestamp
- `notes`: Assignment notes/reason

## Automated Processes

### Daily Escalation Check
Run the command: `php artisan tasks:check-escalations`

This command:
1. Identifies overdue tasks
2. Escalates priorities based on overdue duration
3. Sends overdue notifications
4. Sends due date reminders

### Recommended Scheduling
Add to cron/scheduler:
```
0 9 * * * cd /path-to-your-project && php artisan tasks:check-escalations
```

## Business Logic Validation

### Assignment Validation
```php
// User must have department
if (!$assignedUser->department_id) {
    throw new Exception("Cannot assign task to user without department");
}

// Prevent duplicate assignments
$existingTasks = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
    ->where('department_id', $assignedUser->department_id)
    ->where('type', $task->type)
    ->where('id', '!=', $task->id)
    ->count();

if ($existingTasks > 0) {
    // Log warning but allow (configurable)
}
```

### Priority Escalation
```php
$daysOverdue = $task->due_date->diffInDays(now());

$newPriority = match(true) {
    $daysOverdue >= 7 => 'urgent',
    $daysOverdue >= 3 => 'high',
    $daysOverdue >= 1 => 'high',
    default => $currentPriority
};
```

## Frontend Integration

### Components Updated
- `TaskAssignmentModal.vue`: Updated to fetch users from API
- `useTaskAssignment.ts`: Updated API endpoints

### User Experience
1. Project managers see unassigned tasks in enquiry details
2. Click "Assign" to open assignment modal
3. Select user, set priority, due date, and notes
4. Assignment creates notification and history entry
5. Users receive real-time notifications

## Monitoring and Alerts

### Key Metrics to Monitor
- Average time to task assignment
- Task completion rates by department
- Overdue task counts
- Reassignment frequency

### Alert Triggers
- High number of overdue tasks
- Tasks unassigned for extended periods
- Frequent reassignments of same task

## Security Considerations

### Permission Levels
- **Project Managers**: Full assign/reassign capabilities
- **Department Heads**: Can assign within their department
- **Regular Users**: View only their assigned tasks

### Audit Trail
- All assignments logged with user, timestamp, and reason
- Reassignments tracked separately
- Notifications logged for compliance

## Future Enhancements

### Potential Improvements
1. **Bulk Assignment**: Assign multiple tasks at once
2. **Smart Assignment**: AI-based user recommendations
3. **SLA Management**: Service level agreement tracking
4. **Resource Balancing**: Automatic load balancing across users
5. **Calendar Integration**: Sync with external calendars

### Configuration Options
- Custom escalation rules per department
- Configurable reminder timings
- Department-specific assignment rules
