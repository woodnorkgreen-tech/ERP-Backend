<?php

/*
 * SMS copies of in-app notifications. In-app, email and push notifications are
 * not changed by this file: SMS is an extra channel that is sent alongside them.
 * Texts go out through the same TextSMS account the Logistics module uses
 * (TEXTSMS_* in .env).
 */
return [
    // Master switch. Set SMS_NOTIFICATIONS_ENABLED=false in .env to stop all notification SMS.
    'enabled' => env('SMS_NOTIFICATIONS_ENABLED', true),

    // Notifications that belong to these modules are never texted.
    'exclude_modules' => ['projects'],

    // Individual notification types that are never texted (noisy / repeating ones).
    'exclude_types' => [
        'universal_task_due_soon',
        'universal_task_overdue',
        'universal_task_status_changed',
    ],

    // A type containing any of these words is texted from its title only, saying what it is
    // (no names, amounts or case details go over SMS).
    'generic_type_patterns' => [
        'discipline', 'disciplinary', 'grievance', 'hr_action',
        'payroll', 'payslip', 'salary_advance', 'compensation',
    ],

    // Longest text sent (two SMS parts).
    'max_length' => 300,

    // Who is told when a material list needs Production approval: people in a department whose
    // name contains one of these words, or holding one of these roles.
    'production_department_keywords' => ['production'],
    'production_roles' => ['Production'],

    // Department-wide texts go to everyone in the department, the lead included.
    // (Texts that only concern the lead, such as a leave request awaiting lead approval, go to the lead alone.)
    'exclude_department_leads' => false,
];
