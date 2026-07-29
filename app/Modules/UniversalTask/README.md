# Task Tracker API Documentation

## Overview

Task Tracker provides a comprehensive task management solution that can be integrated across different departments and projects within the ERP system. The backend module is still named `UniversalTask` internally, while the user-facing product name is Task Tracker. It supports hierarchical tasks, dependencies, assignments, labels, issues, experience logging, archiving, and attachments.

## Base URL

```
/api/universal-tasks
```

All endpoints require authentication via Sanctum token.

## Core Endpoints

### Tasks

#### List Tasks
```http
GET /api/universal-tasks/tasks
```

**Query Parameters:**
- `page` (integer): Page number for pagination
- `per_page` (integer): Items per page (max 100)
- `search` (string): Search in title and description
- `status` (string): Filter by status (pending, in_progress, review, completed, cancelled)
- `overdue` (boolean): Filter open tasks whose due date has passed. Overdue is computed from `due_date`; it is not a task status.
- `priority` (string): Filter by priority (low, medium, high, urgent)
- `task_type` (string): Filter by task type
- `department_id` (integer): Filter by department
- `assigned_user_id` (integer|array): Filter by one or more assigned users
- `label_ids` (array): Filter by labels
- `archived` (string): Archive mode (without, only, with)
- `due_date_from` (date): Filter tasks due after this date
- `due_date_to` (date): Filter tasks due before this date
- `sort_by` (string): Sort field (created_at, updated_at, due_date, priority, status)
- `sort_direction` (string): Sort direction (asc, desc)

**Response:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [...],
    "per_page": 25,
    "total": 100
  }
}
```

#### Create Task
```http
POST /api/universal-tasks/tasks
```

**Request Body:**
```json
{
  "title": "Implement user authentication",
  "description": "Add JWT-based authentication system",
  "task_type": "feature",
  "status": "pending",
  "priority": "high",
  "parent_task_id": null,
  "taskable_type": "App\\Models\\Project",
  "taskable_id": 1,
  "department_id": 1,
  "assigned_user_id": 2,
  "estimated_hours": 16,
  "due_date": "2024-02-15",
  "label_ids": [1, 2],
  "metadata": {},
  "context": {}
}
```

#### Get Task Details
```http
GET /api/universal-tasks/tasks/{task}
```

#### Update Task
```http
PUT /api/universal-tasks/tasks/{task}
```

#### Delete Task
```http
DELETE /api/universal-tasks/tasks/{task}
```

#### Update Task Status
```http
PATCH /api/universal-tasks/tasks/{task}/status
```

**Request Body:**
```json
{
  "status": "in_progress",
  "notes": "Started working on authentication implementation"
}
```

#### Assign Task
```http
POST /api/universal-tasks/tasks/{task}/assign
```

**Request Body:**
```json
{
  "user_ids": [2, 3],
  "role": "developer",
  "replace_existing": false
}
```

#### Archive Tasks
```http
POST /api/universal-tasks/tasks/archive
```

Archives completed or cancelled tasks. `force` ignores the age threshold only; active tasks are never archived.

#### Restore Archived Task
```http
PATCH /api/universal-tasks/tasks/{task}/restore-archive
```

### Subtasks

#### List Subtasks
```http
GET /api/universal-tasks/tasks/{task}/subtasks
```

#### Create Subtask
```http
POST /api/universal-tasks/tasks/{task}/subtasks
```

#### Get Subtask Hierarchy
```http
GET /api/universal-tasks/tasks/{task}/hierarchy
```

### Comments

#### List Comments
```http
GET /api/universal-tasks/tasks/{task}/comments
```

#### Add Comment
```http
POST /api/universal-tasks/tasks/{task}/comments
```

**Request Body:**
```json
{
  "content": "This looks good, but we need to handle edge cases.",
  "parent_comment_id": null
}
```

#### Reply to Comment
```http
POST /api/universal-tasks/tasks/{task}/comments/{comment}/reply
```

### Attachments

#### List Attachments
```http
GET /api/universal-tasks/tasks/{task}/attachments
```

#### Upload Attachment
```http
POST /api/universal-tasks/tasks/{task}/attachments
```

**Content-Type:** `multipart/form-data`

**Form Data:**
- `file`: The file to upload (max 50MB)
- `description`: Optional description

#### Download Attachment
```http
GET /api/universal-tasks/attachments/{attachment}/download
```

#### Get Attachment Versions
```http
GET /api/universal-tasks/attachments/file/{filename}/versions
```

### Issues

#### List Issues
```http
GET /api/universal-tasks/issues?task_id={task}
```

#### Report Issue
```http
POST /api/universal-tasks/issues
```

**Request Body:**
```json
{
  "title": "Login form validation broken",
  "description": "Email validation regex is not working properly",
  "issue_type": "bug",
  "severity": "high",
  "assigned_to": 3
}
```

#### Resolve Issue
```http
PATCH /api/universal-tasks/issues/{issue}/resolve
```

### Experience Logs

#### List Experience Logs
```http
GET /api/universal-tasks/experience-logs?task_id={task}
```

#### Add Experience Log
```http
POST /api/universal-tasks/experience-logs
```

**Request Body:**
```json
{
  "title": "JWT Implementation Lessons",
  "content": "Key takeaways: Always validate tokens on both client and server, implement proper refresh logic, store tokens securely.",
  "log_type": "lesson_learned",
  "is_public": true
}
```

### Templates

#### List Templates
```http
GET /api/universal-tasks/templates
```

**Query Parameters:**
- `category` (string): Filter by category
- `search` (string): Search in name and description
- `include_inactive` (boolean): Include inactive templates

#### Create Template
```http
POST /api/universal-tasks/templates
```

**Request Body:**
```json
{
  "name": "Software Development Workflow",
  "description": "Standard workflow for software development tasks",
  "category": "development",
  "template_data": {
    "tasks": [
      {
        "title": "Requirements Analysis",
        "description": "Gather and analyze requirements",
        "task_type": "analysis",
        "priority": "high",
        "estimated_hours": 8
      },
      {
        "title": "Implementation",
        "description": "Implement the solution",
        "task_type": "development",
        "priority": "high",
        "estimated_hours": 32
      }
    ],
    "dependencies": [
      {
        "task_id": "task_1",
        "depends_on_task_id": "task_2",
        "dependency_type": "blocks"
      }
    ]
  },
  "variables": {
    "project_name": {
      "required": true
    },
    "deadline": {
      "required": false
    }
  },
}
```

#### Instantiate Template
```http
POST /api/universal-tasks/templates/{template}/instantiate
```

**Request Body:**
```json
{
  "variables": {
    "project_name": "User Authentication System",
    "deadline": "2024-03-01"
  },
  "taskable_type": "App\\Models\\Project",
  "taskable_id": 1,
  "department_id": 1,
  "assigned_user_id": 2
}
```

### History and Activity

#### Get Task History
```http
GET /api/universal-tasks/tasks/{task}/history
```

#### Get Task Activity Feed
```http
GET /api/universal-tasks/tasks/{task}/activity
```

## Integration Endpoints

### Projects Integration

#### Get Project Universal Tasks
```http
GET /api/projects/projects/{projectId}/universal-tasks
```

#### Create Universal Task for Project
```http
POST /api/projects/projects/{projectId}/universal-tasks
```

### Department Integration

#### Get Department Universal Tasks
```http
GET /api/projects/universal-tasks/department
```

#### Quick Task Creation
```http
POST /api/universal-tasks/quick-create
```

## Response Format

All API responses follow this structure:

**Success Response:**
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": { ... }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Invalid input data",
    "details": { ... }
  }
}
```

## Error Codes

- `VALIDATION_ERROR`: Input validation failed
- `INSUFFICIENT_PERMISSIONS`: User lacks required permissions
- `NOT_FOUND`: Resource not found
- `INTERNAL_ERROR`: Server error
- `INVALID_TRANSITION`: Invalid status transition
- `CREATION_FAILED`: Failed to create resource
- `UPDATE_FAILED`: Failed to update resource
- `DELETION_FAILED`: Failed to delete resource

## Permissions

The system uses role-based permissions:

- `TASK_READ`: View tasks
- `TASK_CREATE`: Create tasks
- `TASK_UPDATE`: Edit tasks
- `TASK_DELETE`: Delete tasks
- `TASK_ASSIGN`: Assign tasks to users
- `TASK_COMPLETE`: Mark tasks as completed
- `DEPARTMENT_ACCESS`: Access department-specific data
- `DEPARTMENT_MANAGE`: Manage department resources

## Rate Limiting

API endpoints are rate-limited. Standard limits:
- Read operations: 60 requests per minute
- Write operations: 30 requests per minute
- File uploads: 10 requests per minute

## Webhooks

The system supports webhooks for real-time notifications:

- Task created/updated/deleted
- Status changes
- Assignments
- Comments added
- Issues reported/resolved

Configure webhooks in the admin panel under System Settings > Integrations.
