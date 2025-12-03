<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Universal Task System Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the Universal Task System
    | module. These settings control various aspects of task management,
    | notifications, caching, and performance optimization.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Task Status Options
    |--------------------------------------------------------------------------
    |
    | Define the available task statuses. These can be customized per
    | department or task type if needed.
    |
    */
    'statuses' => [
        'pending',
        'in_progress',
        'blocked',
        'review',
        'completed',
        'cancelled',
        'overdue',
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Priority Levels
    |--------------------------------------------------------------------------
    |
    | Define the available priority levels for tasks.
    |
    */
    'priorities' => [
        'low',
        'medium',
        'high',
        'urgent',
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Issue Severity Levels
    |--------------------------------------------------------------------------
    |
    | Define severity levels for task issues.
    |
    */
    'issue_severities' => [
        'low',
        'medium',
        'high',
        'critical',
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Issue Types
    |--------------------------------------------------------------------------
    |
    | Define the types of issues that can be logged against tasks.
    |
    */
    'issue_types' => [
        'blocker',
        'technical',
        'resource',
        'dependency',
        'general',
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Dependency Types
    |--------------------------------------------------------------------------
    |
    | Define the types of dependencies between tasks.
    |
    */
    'dependency_types' => [
        'blocks',
        'blocked_by',
        'relates_to',
        'duplicates',
    ],

    /*
    |--------------------------------------------------------------------------
    | Experience Log Types
    |--------------------------------------------------------------------------
    |
    | Define the types of experience logs that can be recorded.
    |
    */
    'experience_log_types' => [
        'observation',
        'learning',
        'best_practice',
        'recommendation',
        'issue_resolution',
        'general',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configure notification behavior for task events.
    |
    */
    'notifications' => [
        'enabled' => true,
        'channels' => ['database', 'mail'], // Available: database, mail, broadcast
        'reminder_intervals' => [
            'first' => 24, // hours before due date
            'second' => 1, // hours before due date
        ],
        'critical_issue_notify_supervisors' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Settings
    |--------------------------------------------------------------------------
    |
    | Configure caching durations for various data types.
    |
    */
    'cache' => [
        'enabled' => true,
        'task_lists_ttl' => 300, // 5 minutes
        'dashboard_metrics_ttl' => 900, // 15 minutes
        'user_assignments_ttl' => 600, // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination Settings
    |--------------------------------------------------------------------------
    |
    | Configure default pagination settings.
    |
    */
    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Archiving Settings
    |--------------------------------------------------------------------------
    |
    | Configure task archiving behavior.
    |
    */
    'archiving' => [
        'enabled' => true,
        'archive_after_days' => 365, // Archive completed tasks after 1 year
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configure file attachment settings.
    |
    */
    'attachments' => [
        'max_file_size' => 10240, // KB (10 MB)
        'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'zip'],
        'storage_disk' => 'local',
        'storage_path' => 'task-attachments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure API rate limiting per endpoint type.
    |
    */
    'rate_limiting' => [
        'standard' => 60, // requests per minute
        'analytics' => 30, // requests per minute
        'file_upload' => 10, // requests per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Subtask Settings
    |--------------------------------------------------------------------------
    |
    | Configure subtask behavior.
    |
    */
    'subtasks' => [
        'max_nesting_level' => null, // null = unlimited
        'inherit_assignment' => true, // Subtasks inherit parent assignment if not explicitly assigned
        'auto_complete_parent' => false, // Automatically complete parent when all subtasks are done
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Settings
    |--------------------------------------------------------------------------
    |
    | Configure task template behavior.
    |
    */
    'templates' => [
        'versioning_enabled' => true,
        'allow_variable_substitution' => true,
    ],
];
