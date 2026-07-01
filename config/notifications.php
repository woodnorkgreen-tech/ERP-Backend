<?php

return [
    'unread_count_cache_ttl' => 15,

    'channels' => ['database', 'mail', 'push', 'whatsapp'],

    'implemented_channels' => ['database', 'mail', 'push'],

    'types' => [
        'leave_request_submitted' => [
            'module' => 'hr',
            'label' => 'Leave Request Submitted',
            'default_channels' => ['database'],
            'urgency' => 'info',
        ],
        'leave_request_lead_approved' => [
            'module' => 'hr',
            'label' => 'Leave Request Lead Approved',
            'default_channels' => ['database'],
            'urgency' => 'success',
        ],
        'leave_request_approved' => [
            'module' => 'hr',
            'label' => 'Leave Request Approved',
            'default_channels' => ['database'],
            'urgency' => 'success',
        ],
        'leave_request_rejected' => [
            'module' => 'hr',
            'label' => 'Leave Request Rejected',
            'default_channels' => ['database'],
            'urgency' => 'warning',
        ],
        'leave_request_cancelled' => [
            'module' => 'hr',
            'label' => 'Leave Request Cancelled',
            'default_channels' => ['database'],
            'urgency' => 'warning',
        ],
        'leave_request_recalled' => [
            'module' => 'hr',
            'label' => 'Leave Request Recalled',
            'default_channels' => ['database'],
            'urgency' => 'critical',
        ],

        'onboarding_started' => [
            'module' => 'hr',
            'label' => 'Onboarding Started',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'info',
        ],
        'onboarding_hr_approved' => [
            'module' => 'hr',
            'label' => 'HR Gate Approved',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'success',
        ],
        'onboarding_hr_gate_pending' => [
            'module' => 'hr',
            'label' => 'HR Gate Pending Approval',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'warning',
        ],
        'onboarding_handover_recorded' => [
            'module' => 'hr',
            'label' => 'Handover Recorded',
            'default_channels' => ['database'],
            'urgency' => 'info',
        ],
        'onboarding_completed' => [
            'module' => 'hr',
            'label' => 'Onboarding Completed',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'success',
        ],

        'incident_reported' => [
            'module' => 'hr', 'label' => 'Incident Reported',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'incident_status_changed' => [
            'module' => 'hr', 'label' => 'Incident Status Changed',
            'default_channels' => ['database', 'mail'], 'urgency' => 'info',
        ],
        'logistics_trip_requested' => [
            'module' => 'logistics', 'label' => 'Trip Requested',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'logistics_trip_status_changed' => [
            'module' => 'logistics', 'label' => 'Trip Status Changed',
            'default_channels' => ['database', 'push'], 'urgency' => 'info',
        ],
        'production_work_order_assigned' => [
            'module' => 'production', 'label' => 'Work Order Assigned',
            'default_channels' => ['database', 'push'], 'urgency' => 'info',
        ],
        'production_work_order_status_changed' => [
            'module' => 'production', 'label' => 'Work Order Status Changed',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'production_ncr_raised' => [
            'module' => 'production', 'label' => 'Non-Conformance Raised',
            'default_channels' => ['database', 'mail', 'push'], 'urgency' => 'critical',
        ],
        'procurement_requisition_submitted' => [
            'module' => 'procurement-stores', 'label' => 'Requisition Submitted',
            'default_channels' => ['database', 'push'], 'urgency' => 'warning',
        ],
        'procurement_requisition_status_changed' => [
            'module' => 'procurement-stores', 'label' => 'Requisition Status Changed',
            'default_channels' => ['database', 'mail'], 'urgency' => 'info',
        ],
        'procurement_purchase_order_status_changed' => [
            'module' => 'procurement-stores', 'label' => 'Purchase Order Status Changed',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'project_activity' => [
            'module' => 'projects', 'label' => 'Project Activity',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'task_activity' => [
            'module' => 'universal-task', 'label' => 'Task Activity',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'finance_requisition_pending' => [
            'module' => 'finance', 'label' => 'Finance Requisition Pending',
            'default_channels' => ['database', 'push'], 'urgency' => 'warning',
        ],
    ],
];
