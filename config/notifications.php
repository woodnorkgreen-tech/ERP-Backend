<?php

return [
    'unread_count_cache_ttl' => 15,

    'channels' => ['database', 'mail', 'push', 'whatsapp'],

    'implemented_channels' => ['database', 'mail', 'push'],

    'types' => [
        'leave_request_submitted' => [
            'module' => 'hr',
            'label' => 'Leave Request Submitted',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'info',
        ],
        'leave_request_lead_approved' => [
            'module' => 'hr',
            'label' => 'Leave Request Lead Approved',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'success',
        ],
        'leave_request_approved' => [
            'module' => 'hr',
            'label' => 'Leave Request Approved',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'success',
        ],
        'leave_request_rejected' => [
            'module' => 'hr',
            'label' => 'Leave Request Rejected',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'warning',
        ],
        'leave_request_cancelled' => [
            'module' => 'hr',
            'label' => 'Leave Request Cancelled',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'warning',
        ],
        'leave_request_recalled' => [
            'module' => 'hr',
            'label' => 'Leave Request Recalled',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'critical',
        ],

        'onboarding_started' => [
            'module' => 'hr',
            'label' => 'Onboarding Started',
            'default_channels' => ['database'],
            'urgency' => 'info',
        ],
        'onboarding_hr_approved' => [
            'module' => 'hr',
            'label' => 'HR Gate Approved',
            'default_channels' => ['database'],
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
            'default_channels' => ['database'],
            'urgency' => 'success',
        ],

        // HR - Offboarding. Routine lifecycle events stay in-app for audit history;
        // only blocked/action-required events interrupt users by email.
        'offboarding_started' => [
            'module' => 'hr', 'label' => 'Offboarding Started',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'offboarding_clearance_updated' => [
            'module' => 'hr', 'label' => 'Offboarding Clearance Updated',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'offboarding_clearance_flagged' => [
            'module' => 'hr', 'label' => 'Offboarding Clearance Flagged',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'offboarding_exit_interview_recorded' => [
            'module' => 'hr', 'label' => 'Exit Interview Recorded',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'offboarding_settlement_calculated' => [
            'module' => 'hr', 'label' => 'Final Settlement Ready for Approval',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
        'offboarding_settlement_approved' => [
            'module' => 'hr', 'label' => 'Final Settlement Approved',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'offboarding_settlement_paid' => [
            'module' => 'hr', 'label' => 'Final Settlement Paid',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'offboarding_completed' => [
            'module' => 'hr', 'label' => 'Offboarding Completed',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'offboarding_cancelled' => [
            'module' => 'hr', 'label' => 'Offboarding Cancelled',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],

        // HR - Employee actions
        'hr_action_recorded' => [
            'module' => 'hr', 'label' => 'HR Action Recorded',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'hr_action_scheduled' => [
            'module' => 'hr', 'label' => 'HR Action Scheduled',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],
        'hr_action_executed' => [
            'module' => 'hr', 'label' => 'HR Action Executed',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'hr_action_formal_notice' => [
            'module' => 'hr', 'label' => 'Formal HR Action Notice',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],

        // HR - Grievances
        'grievance_reported' => [
            'module' => 'hr', 'label' => 'Grievance Reported',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
        'grievance_status_changed' => [
            'module' => 'hr', 'label' => 'Grievance Status Changed',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'grievance_resolved' => [
            'module' => 'hr', 'label' => 'Grievance Resolved',
            'default_channels' => ['database', 'mail'], 'urgency' => 'success',
        ],
        'grievance_escalated' => [
            'module' => 'hr', 'label' => 'Grievance Escalated',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],

        // HR - Discipline
        'discipline_reported' => [
            'module' => 'hr', 'label' => 'Disciplinary Case Reported',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],
        'discipline_show_cause_issued' => [
            'module' => 'hr', 'label' => 'Show Cause Issued',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'discipline_response_submitted' => [
            'module' => 'hr', 'label' => 'Show Cause Response Submitted',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
        'discipline_hearing_scheduled' => [
            'module' => 'hr', 'label' => 'Disciplinary Hearing Scheduled',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'discipline_hearing_recorded' => [
            'module' => 'hr', 'label' => 'Disciplinary Hearing Recorded',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'discipline_warning_issued' => [
            'module' => 'hr', 'label' => 'Disciplinary Warning Issued',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'discipline_appeal_submitted' => [
            'module' => 'hr', 'label' => 'Disciplinary Appeal Submitted',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'discipline_finalized' => [
            'module' => 'hr', 'label' => 'Disciplinary Case Finalized',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
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

        // Projects - Enquiry & task lifecycle
        'enquiry_task_assigned' => [
            'module' => 'projects', 'label' => 'Task Assigned',
            'default_channels' => ['database', 'push'], 'urgency' => 'info',
        ],
        'enquiry_task_reassigned' => [
            'module' => 'projects', 'label' => 'Task Reassigned',
            'default_channels' => ['database', 'push'], 'urgency' => 'info',
        ],
        'enquiry_task_unassigned' => [
            'module' => 'projects', 'label' => 'Task Unassigned',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'enquiry_task_due_soon' => [
            'module' => 'projects', 'label' => 'Task Due Soon',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],
        'enquiry_task_overdue' => [
            'module' => 'projects', 'label' => 'Task Overdue',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
        'enquiry_task_ready' => [
            'module' => 'projects', 'label' => 'Task Ready to Start',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'enquiry_task_completed' => [
            'module' => 'projects', 'label' => 'Task Completed',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'enquiry_created' => [
            'module' => 'projects', 'label' => 'New Project Enquiry',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'project_officer_assigned' => [
            'module' => 'projects', 'label' => 'Project Officer Assigned',
            'default_channels' => ['database', 'push'], 'urgency' => 'info',
        ],
        'quote_approved' => [
            'module' => 'projects', 'label' => 'Quote Approved',
            'default_channels' => ['database', 'mail'], 'urgency' => 'success',
        ],
        'quote_approval_invalidated' => [
            'module' => 'projects', 'label' => 'Quote Approval Invalidated',
            'default_channels' => ['database', 'mail'], 'urgency' => 'critical',
        ],
        'enquiry_status_changed' => [
            'module' => 'projects', 'label' => 'Enquiry Status Update',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'project_completion_blocked' => [
            'module' => 'projects', 'label' => 'Project Completion Blocked',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],
        'deliverables_updated' => [
            'module' => 'projects', 'label' => 'Project Scope Updated',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],
        'pettycash_requisition_submitted' => [
            'module' => 'finance', 'label' => 'Petty Cash Requisition Submitted',
            'default_channels' => ['database', 'push'], 'urgency' => 'warning',
        ],
        'pettycash_requisition_approved' => [
            'module' => 'finance', 'label' => 'Petty Cash Requisition Approved',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'pettycash_requisition_rejected' => [
            'module' => 'finance', 'label' => 'Petty Cash Requisition Rejected',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
        'pettycash_requisition_disbursed' => [
            'module' => 'finance', 'label' => 'Petty Cash Disbursed',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],

        // Universal Task lifecycle
        'universal_task_assigned' => [
            'module' => 'universal-task', 'label' => 'Task Assigned',
            'default_channels' => ['database', 'push'], 'urgency' => 'info',
        ],
        'universal_task_status_changed' => [
            'module' => 'universal-task', 'label' => 'Task Status Updated',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'universal_task_due_soon' => [
            'module' => 'universal-task', 'label' => 'Task Due Soon',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],
        'universal_task_overdue' => [
            'module' => 'universal-task', 'label' => 'Task Overdue',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
        'universal_task_completed' => [
            'module' => 'universal-task', 'label' => 'Task Completed',
            'default_channels' => ['database'], 'urgency' => 'info',
        ],
        'universal_task_user_mentioned' => [
            'module' => 'universal-task', 'label' => 'You Were Mentioned',
            'default_channels' => ['database', 'mail'], 'urgency' => 'info',
        ],
        'universal_task_issue' => [
            'module' => 'universal-task', 'label' => 'Task Issue Update',
            'default_channels' => ['database'], 'urgency' => 'warning',
        ],

        // HR - Payroll
        'payroll_run_processed' => [
            'module' => 'hr', 'label' => 'Payroll Run Processed',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'payroll_run_finalized' => [
            'module' => 'hr', 'label' => 'Payroll Run Finalized',
            'default_channels' => ['database', 'mail'], 'urgency' => 'success',
        ],
        'payroll_payslip_ready' => [
            'module' => 'hr', 'label' => 'Payslip Ready',
            'default_channels' => ['database', 'mail'], 'urgency' => 'info',
        ],

        // HR - Overtime
        'overtime_submitted' => [
            'module' => 'hr', 'label' => 'Overtime Submitted for Approval',
            'default_channels' => ['database', 'push'], 'urgency' => 'warning',
        ],
        'overtime_approved' => [
            'module' => 'hr', 'label' => 'Overtime Approved',
            'default_channels' => ['database'], 'urgency' => 'success',
        ],
        'overtime_rejected' => [
            'module' => 'hr', 'label' => 'Overtime Rejected',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],

        // HR - Salary Advance
        'salary_advance_requested' => [
            'module' => 'hr', 'label' => 'Salary Advance Requested',
            'default_channels' => ['database', 'push'], 'urgency' => 'warning',
        ],
        'salary_advance_approved' => [
            'module' => 'hr', 'label' => 'Salary Advance Approved',
            'default_channels' => ['database', 'mail'], 'urgency' => 'success',
        ],
        'salary_advance_rejected' => [
            'module' => 'hr', 'label' => 'Salary Advance Rejected',
            'default_channels' => ['database', 'mail'], 'urgency' => 'warning',
        ],
    ],
];
