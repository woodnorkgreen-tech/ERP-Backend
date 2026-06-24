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
    const STATUS_CANCELLED = 'cancelled';

    // Enquiry Priorities
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    // Roles with full administrative access to projects
    const ROLES_ADMIN = ['Super Admin', 'Admin', 'Project Manager', 'Project Officer', 'HR'];

    // All roles that participate in the project workflow
    const ROLES_WORKFLOW = [
        'Super Admin', 'Admin', 'Project Manager', 'Project Officer', 'HR',
        'Designer', 'Procurement', 'Production', 'Logistics', 'Stores', 'Accounts', 'Client Service', 'Costing'
    ];

    // Mapping of roles to the task types they are authorized to view/manage
    const TASK_VISIBILITY_MAPPING = [
        'Designer' => ['design', 'site-survey', 'materials'],
        'Costing' => ['materials', 'budget', 'quote', 'quote_approval'],
        'Accounts' => ['materials', 'budget', 'quote', 'quote_approval'],
        'Stores' => ['materials', 'stores', 'budget', 'procurement'],
        'Store Keeper' => ['materials', 'stores', 'budget', 'procurement'],
        'Storekeeper' => ['materials', 'stores', 'budget', 'procurement'],
        'Procurement' => ['materials', 'procurement', 'budget'],
        'Procurement Officer' => ['materials', 'procurement', 'budget'],
        'Production' => ['materials', 'teams', 'production', 'budget'],
    ];

    // Mapping of enquiry statuses to the tasks required to achieve them
    const ENQUIRY_STATUS_REQUISITES = [
        self::STATUS_QUOTE_APPROVED => ['quote_approval'],
        self::STATUS_QUOTE_PREPARED => ['quote'],
        self::STATUS_BUDGET_CREATED => ['budget'],
        self::STATUS_MATERIALS_SPECIFIED => ['materials'],
        self::STATUS_DESIGN_COMPLETED => ['design'],
        self::STATUS_SITE_SURVEY_COMPLETED => ['site-survey'],
    ];

    // Closure-phase task types that must be completed (or skipped) before a project
    // can transition to STATUS_COMPLETED. Only tasks actually selected for the project
    // are evaluated — unselected closure tasks do not block completion.
    const PROJECT_COMPLETION_REQUISITES = ['report'];

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
            self::STATUS_MATERIALS_SPECIFIED,
            self::STATUS_BUDGET_CREATED,
            self::STATUS_QUOTE_PREPARED,
            self::STATUS_QUOTE_APPROVED,
            self::STATUS_AWAITING_DEPOSIT,
            self::STATUS_PLANNING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
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
            self::STATUS_MATERIALS_SPECIFIED,
            self::STATUS_BUDGET_CREATED,
            self::STATUS_QUOTE_PREPARED,
            self::STATUS_QUOTE_APPROVED,
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

    /** Pipeline: post-logging, pre-quote-approval */
    public static function getInProgressEnquiryStatuses(): array
    {
        return [
            self::STATUS_SITE_SURVEY_COMPLETED,
            self::STATUS_DESIGN_COMPLETED,
            self::STATUS_DESIGN_APPROVED,
            self::STATUS_MATERIALS_SPECIFIED,
            self::STATUS_BUDGET_CREATED,
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

    public static function getCancelledStatuses(): array
    {
        return [self::STATUS_CANCELLED];
    }

    public static function getClosedStatuses(): array
    {
        return [self::STATUS_COMPLETED, self::STATUS_CANCELLED];
    }
}
