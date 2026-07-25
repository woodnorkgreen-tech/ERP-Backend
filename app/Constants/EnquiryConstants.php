<?php

namespace App\Constants;

/**
 * Enquiry-related constants
 */
class EnquiryConstants
{
    // Enquiry Statuses
    const STATUS_CLIENT_REGISTERED = 'client_registered';
    const STATUS_ENQUIRY_LOGGED = 'enquiry_logged';
    const STATUS_SITE_SURVEY_COMPLETED = 'site_survey_completed';
    const STATUS_DESIGN_COMPLETED = 'design_completed';
    const STATUS_DESIGN_APPROVED = 'design_approved';
    const STATUS_MATERIALS_SPECIFIED = 'materials_specified';
    const STATUS_BUDGET_CREATED = 'budget_created';
    const STATUS_QUOTE_PREPARED = 'quote_prepared';
    const STATUS_QUOTE_APPROVED = 'quote_approved';
    const STATUS_AWAITING_DEPOSIT = 'awaiting_deposit';
    const STATUS_PLANNING = 'planning';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CLOSED = 'closed';
    const STATUS_CANCELLED = 'cancelled';

    // Enquiry Priorities
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    // Roles with full administrative access to projects
    const ROLES_ADMIN = ['Super Admin', 'Admin', 'Project Manager', 'Project Officer', 'HR'];

    // True system administrators only. Narrower than ROLES_ADMIN — used for
    // gates where a role in ROLES_ADMIN (e.g. Project Officer) is itself one
    // of the parties required to satisfy the gate, so it must not self-bypass.
    const ROLES_SYSTEM_ADMIN = ['Super Admin', 'Admin'];

    // Financial quote tasks contain pricing and approval data. Assignment or
    // department membership must never widen this role boundary.
    const FINANCIAL_QUOTE_TASK_TYPES = ['quote', 'quote_approval'];
    const FINANCIAL_QUOTE_ROLES = ['Super Admin', 'Project Manager', 'Costing', 'Accounts', 'Finance'];

    // All roles that participate in the project workflow
    const ROLES_WORKFLOW = [
        'Super Admin', 'Admin', 'Project Manager', 'Project Officer', 'HR', 'Finance',
        'Designer', 'Procurement', 'Production', 'Logistics', 'Stores', 'Accounts', 'Client Service', 'Costing'
    ];

    // Mapping of roles to the task types they are authorized to view/manage
    const TASK_VISIBILITY_MAPPING = [
        'Designer' => ['design', 'site-survey', 'materials'],
        'Costing' => ['materials', 'budget', 'quote', 'quote_approval'],
        'Accounts' => ['materials', 'budget', 'quote', 'quote_approval'],
        'Finance' => ['budget', 'quote', 'quote_approval'],
        'Stores' => ['materials', 'stores', 'budget', 'procurement'],
        'Store Keeper' => ['materials', 'stores', 'budget', 'procurement'],
        'Storekeeper' => ['materials', 'stores', 'budget', 'procurement'],
        'Procurement' => ['materials', 'procurement', 'budget'],
        'Procurement Officer' => ['materials', 'procurement', 'budget'],
        'Production' => ['materials', 'teams', 'production', 'budget'],
    ];

    // Mapping of enquiry statuses to the tasks required to achieve them.
    // ORDER IS CRITICAL — SyncEnquiryStatusAction breaks on the first match,
    // so statuses must be ordered highest milestone first.
    const ENQUIRY_STATUS_REQUISITES = [
        self::STATUS_QUOTE_APPROVED        => ['quote_approval'],
        self::STATUS_QUOTE_PREPARED        => ['quote'],
        self::STATUS_DESIGN_COMPLETED      => ['design'],
        self::STATUS_SITE_SURVEY_COMPLETED => ['site-survey'],
        self::STATUS_MATERIALS_SPECIFIED   => ['materials'],
        self::STATUS_BUDGET_CREATED        => ['budget'],
    ];

    // Closure-phase task types that must be completed before a completed project
    // can transition to STATUS_CLOSED.
    const PROJECT_CLOSURE_REQUISITES = ['handover', 'report'];

    // Backwards-compatible alias for older completion-readiness callers.
    const PROJECT_COMPLETION_REQUISITES = self::PROJECT_CLOSURE_REQUISITES;

    // Enquiry number prefix
    const ENQUIRY_PREFIX = 'ENQ';

    // Project ID prefix
    const PROJECT_PREFIX = 'WNG';

    // Non-profit prefixes
    const INTERNAL_PREFIX = 'INT';
    const SPONSORSHIP_PREFIX = 'SPN';

    // Pagination default
    const PAGINATION_PER_PAGE = 15;

    /**
     * Get all enquiry statuses
     */
    public static function getAllStatuses(): array
    {
        return [
            self::STATUS_CLIENT_REGISTERED,
            self::STATUS_ENQUIRY_LOGGED,
            self::STATUS_SITE_SURVEY_COMPLETED,
            self::STATUS_DESIGN_COMPLETED,
            self::STATUS_DESIGN_APPROVED,
            self::STATUS_QUOTE_PREPARED,
            self::STATUS_QUOTE_APPROVED,
            self::STATUS_MATERIALS_SPECIFIED,
            self::STATUS_BUDGET_CREATED,
            self::STATUS_AWAITING_DEPOSIT,
            self::STATUS_PLANNING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_CLOSED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Get all priorities
     */
    public static function getAllPriorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_MEDIUM,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }



    /**
     * Get active statuses (for filtering)
     */
    public static function getActiveStatuses(): array
    {
        return [
            self::STATUS_CLIENT_REGISTERED,
            self::STATUS_ENQUIRY_LOGGED,
            self::STATUS_SITE_SURVEY_COMPLETED,
            self::STATUS_DESIGN_COMPLETED,
            self::STATUS_DESIGN_APPROVED,
            self::STATUS_QUOTE_PREPARED,
            self::STATUS_QUOTE_APPROVED,
            self::STATUS_MATERIALS_SPECIFIED,
            self::STATUS_BUDGET_CREATED,
            self::STATUS_AWAITING_DEPOSIT,
            self::STATUS_PLANNING,
            self::STATUS_IN_PROGRESS,
        ];
    }

    /** Enquiries that have an approved quote and are live projects */
    public static function getApprovedProjectStatuses(): array
    {
        return [
            self::STATUS_QUOTE_APPROVED,
            self::STATUS_PLANNING,
            self::STATUS_IN_PROGRESS,
        ];
    }

    /** Pipeline: post-logging, before the client-approved quote boundary */
    public static function getInProgressEnquiryStatuses(): array
    {
        return [
            self::STATUS_SITE_SURVEY_COMPLETED,
            self::STATUS_DESIGN_COMPLETED,
            self::STATUS_DESIGN_APPROVED,
            self::STATUS_QUOTE_PREPARED,
        ];
    }

    /** Pre-production: quote approved but not yet in progress */
    public static function getPreProductionStatuses(): array
    {
        return [
            self::STATUS_QUOTE_APPROVED,
            self::STATUS_PLANNING,
        ];
    }

    public static function getCompletedStatuses(): array
    {
        return [self::STATUS_COMPLETED];
    }

    public static function getFormallyClosedStatuses(): array
    {
        return [self::STATUS_CLOSED];
    }

    public static function getCancelledStatuses(): array
    {
        return [self::STATUS_CANCELLED];
    }

    public static function getClosedStatuses(): array
    {
        return [self::STATUS_CLOSED, self::STATUS_CANCELLED];
    }
}
