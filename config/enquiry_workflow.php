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
            'phase' => 'Concept & Survey',
            'notes' => 'Conduct site survey for the enquiry'
        ],
        [
            'title' => 'Design & Concept Development',
            'type' => 'design',
            'phase' => 'Concept & Survey',
            'notes' => 'Create design concepts and mockups'
        ],
        [
            'title' => 'Quote Preparation',
            'type' => 'quote',
            'phase' => 'Financials & Approval',
            'notes' => 'Prepare the client-facing quote with scope, margins, and commercial terms'
        ],
        [
            'title' => 'Quote Approval',
            'type' => 'quote_approval',
            'phase' => 'Financials & Approval',
            'notes' => 'Approve and lock the accepted quote version before execution planning'
        ],
        [
            'title' => 'Material & Cost Listing',
            'type' => 'materials',
            'phase' => 'Financials & Approval',
            'notes' => 'Generate the materials list from the approved quote scope'
        ],
        [
            'title' => 'Budget Creation',
            'type' => 'budget',
            'phase' => 'Financials & Approval',
            'notes' => 'Create the internal execution budget from approved materials and operational costs'
        ],
        [
            'title' => 'Procurement & Inventory Management',
            'type' => 'procurement',
            'phase' => 'Fabrication & Inventory',
            'notes' => 'Manage procurement and inventory'
        ],
        [
            'title' => 'Teams',
            'type' => 'teams',
            'phase' => 'Field Operations',
            'notes' => 'Manage project teams'
        ],
        [
            'title' => 'Production',
            'type' => 'production',
            'phase' => 'Fabrication & Inventory',
            'notes' => 'Handle production activities'
        ],
        [
            'title' => 'Logistics',
            'type' => 'logistics',
            'phase' => 'Field Operations',
            'notes' => 'Manage logistics and transportation'
        ],
        [
            'title' => 'Event Setup & Execution',
            'type' => 'setup',
            'phase' => 'Field Operations',
            'notes' => 'Set up event and execute'
        ],
        [
            'title' => 'Client Handover',
            'type' => 'handover',
            'phase' => 'Project Closure',
            'notes' => 'Hand over to client'
        ],
        [
            'title' => 'Set Down & Return',
            'type' => 'setdown',
            'phase' => 'Field Operations',
            'notes' => 'Set down and return equipment'
        ],
        [
            'title' => 'Archival & Reporting',
            'type' => 'report',
            'phase' => 'Project Closure',
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
            'label' => 'Full Event Production',
            'description' => 'Complete event lifecycle from survey to reporting',
            'tasks' => ['site-survey', 'design', 'quote', 'quote_approval', 'materials', 'budget', 'procurement', 'teams', 'production', 'logistics', 'setup', 'handover', 'setdown', 'report']
        ],
        'proposal' => [
            'label' => 'Proposal Development',
            'description' => 'Survey, design, and client quote approval without execution',
            'tasks' => ['site-survey', 'design', 'quote', 'quote_approval']
        ],
        'branding' => [
            'label' => 'Branding & Merchandising',
            'description' => 'Branding projects with design, production and delivery',
            'tasks' => ['design', 'quote', 'quote_approval', 'materials', 'budget', 'procurement', 'production', 'logistics', 'handover', 'report']
        ],
        'fabrication' => [
            'label' => 'Fabrication Project',
            'description' => 'Custom manufacturing and installation builds',
            'tasks' => ['site-survey', 'design', 'quote', 'quote_approval', 'materials', 'budget', 'procurement', 'production', 'logistics', 'handover', 'report']
        ],
        'installation' => [
            'label' => 'Installation & Setup',
            'description' => 'Deployment of ready-made assets with setup',
            'tasks' => ['site-survey', 'quote', 'quote_approval', 'materials', 'budget', 'procurement', 'teams', 'logistics', 'setup', 'handover', 'report']
        ],
        'delivery_only' => [
            'label' => 'Simple Delivery',
            'description' => 'Procurement and delivery without production/setup',
            'tasks' => ['quote', 'quote_approval', 'materials', 'budget', 'procurement', 'logistics', 'handover']
        ],
        'design_only' => [
            'label' => 'Design & Consultation',
            'description' => 'Service delivery focused on creative and advisory',
            'tasks' => ['site-survey', 'design', 'quote', 'quote_approval', 'handover', 'report']
        ],
        'internal_prod' => [
            'label' => 'Internal Production',
            'description' => 'In-house manufacturing for departmental use',
            'tasks' => ['design', 'quote_approval', 'materials', 'budget', 'procurement', 'teams', 'production', 'handover', 'report'],
            'title_overrides' => [
                'quote_approval' => 'Internal Approval',
            ]
        ],
        'internal_job' => [
            'label' => 'Internal Maintenance Job',
            'description' => 'Company facility repairs and office maintenance',
            'tasks' => ['site-survey', 'quote_approval', 'materials', 'budget', 'procurement', 'teams', 'setup', 'handover', 'report'],
            'title_overrides' => [
                'quote_approval' => 'Maintenance Approval',
                'setup' => 'Repair Works'
            ]
        ],
        'sponsorship' => [
            'label' => 'Corporate Sponsorship',
            'description' => 'Marketing-led brand exposure at external events',
            'tasks' => ['design', 'quote_approval', 'materials', 'budget', 'production', 'logistics', 'setup', 'handover', 'report'],
            'title_overrides' => [
                'quote_approval' => 'Sponsorship Approval',
                'setup' => 'Event Activation'
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Dependencies
    |--------------------------------------------------------------------------
    |
    | The single source of truth for workflow ordering. Each key is a task type;
    | the value lists the task types that must be completed (or skipped) before
    | it. A dependency is only enforced when that prerequisite actually exists on
    | the enquiry, so presets that omit a task type are unaffected.
    |
    | Consumed by:
    |   - EnquiryWorkflowService (gates completion, announces unblocked tasks)
    |   - EnquiryTask::blockingPrerequisiteTitles() -> API is_blocked/blocked_by
    |   - the frontend (via is_blocked/blocked_by), so UI locks and backend
    |     enforcement never drift. Keep this list authoritative.
    |
    */

    'task_dependencies' => [
        'design'         => ['site-survey'],
        'quote'          => ['design'],
        'quote_approval' => ['quote'],
        'materials'      => ['quote_approval'],
        'budget'         => ['materials'],
        'procurement'    => ['quote_approval', 'materials', 'budget'],
        'production'     => ['quote_approval', 'materials', 'budget'],
        'teams'          => ['quote_approval', 'design'],
        'logistics'      => ['production', 'procurement'],
        'setup'          => ['logistics', 'production'],
        'handover'       => ['setup', 'production', 'logistics'],
        'setdown'        => ['handover'],
        'report'         => ['handover', 'setdown'],
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
