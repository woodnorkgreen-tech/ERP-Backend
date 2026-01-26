<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enquiry Workflow Task Templates
    |--------------------------------------------------------------------------
    |
    | Default task templates that are created for each new enquiry.
    | Each task has a title, type, and default notes.
    |
    */

    'task_templates' => [
        [
            'title' => 'Site Survey',
            'type' => 'site-survey',
            'notes' => 'Conduct site survey for the enquiry'
        ],
        [
            'title' => 'Design & Concept Development',
            'type' => 'design',
            'notes' => 'Create design concepts and mockups'
        ],
        [
            'title' => 'Material & Cost Listing',
            'type' => 'materials',
            'notes' => 'Specify and source materials for the project'
        ],
        [
            'title' => 'Budget Creation',
            'type' => 'budget',
            'notes' => 'Create budget for the project'
        ],
        [
            'title' => 'Quote Preparation',
            'type' => 'quote',
            'notes' => 'Prepare final quote for the project'
        ],
        [
            'title' => 'Quote Approval',
            'type' => 'quote_approval',
            'notes' => 'Approve the prepared quote'
        ],
        [
            'title' => 'Procurement & Inventory Management',
            'type' => 'procurement',
            'notes' => 'Manage procurement and inventory'
        ],
        [
            'title' => 'Teams',
            'type' => 'teams',
            'notes' => 'Manage project teams'
        ],
        [
            'title' => 'Production',
            'type' => 'production',
            'notes' => 'Handle production activities'
        ],
        [
            'title' => 'Logistics',
            'type' => 'logistics',
            'notes' => 'Manage logistics and transportation'
        ],
        [
            'title' => 'Event Setup & Execution',
            'type' => 'setup',
            'notes' => 'Set up event and execute'
        ],
        [
            'title' => 'Client Handover',
            'type' => 'handover',
            'notes' => 'Hand over to client'
        ],
        [
            'title' => 'Set Down & Return',
            'type' => 'setdown',
            'notes' => 'Set down and return equipment'
        ],
        [
            'title' => 'Archival & Reporting',
            'type' => 'report',
            'notes' => 'Archive and generate reports'
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Task Presets
    |--------------------------------------------------------------------------
    |
    | Predefined task combinations for different project types.
    | Users can select a preset which auto-selects appropriate tasks.
    |
    */

    'task_presets' => [
        'full_event' => [
            'label' => 'Full Event (All Tasks)',
            'description' => 'Complete event lifecycle from survey to reporting',
            'tasks' => ['site-survey', 'design', 'materials', 'budget', 'quote', 'quote_approval', 'procurement', 'teams', 'production', 'logistics', 'setup', 'handover', 'setdown', 'report']
        ],
        'delivery_only' => [
            'label' => 'Delivery Only',
            'description' => 'Simple delivery projects without design/production',
            'tasks' => ['materials', 'procurement', 'logistics', 'handover']
        ],
        'branding' => [
            'label' => 'Branding/Merchandising',
            'description' => 'Branding projects with design and production',
            'tasks' => ['design', 'materials', 'budget', 'quote', 'quote_approval', 'production', 'handover']
        ],
        'design_only' => [
            'label' => 'Design Only',
            'description' => 'Concept and design delivery without execution',
            'tasks' => ['site-survey', 'design', 'handover', 'report']
        ],
        'consultation' => [
            'label' => 'Consultation/Advisory',
            'description' => 'Advisory services without physical delivery',
            'tasks' => ['site-survey', 'design', 'budget', 'quote', 'quote_approval', 'report']
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Dependencies
    |--------------------------------------------------------------------------
    |
    | Define task dependencies for validation warnings
    |
    */

    'task_dependencies' => [
        'quote_approval' => ['quote'],
        'procurement' => ['materials'],
        'production' => ['materials', 'budget'],
        'logistics' => ['procurement'],
        'setup' => ['logistics'],
        'setdown' => ['setup'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Escalation Settings
    |--------------------------------------------------------------------------
    |
    | Settings for automatic task priority escalation based on overdue duration.
    |
    */

    'escalation' => [
        'urgent_threshold_days' => 7,
        'high_threshold_days' => 3,
        'medium_threshold_days' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminder Settings
    |--------------------------------------------------------------------------
    |
    | Settings for due date reminders.
    |
    */

    'reminders' => [
        'due_soon_days' => 1,
        'requiring_attention_days' => 2,
    ],
];
