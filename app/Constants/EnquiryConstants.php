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
    const STATUS_PLANNING = 'planning';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Enquiry Priorities
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    // Roles that can access projects enquiries
    const PROJECT_ACCESS_ROLES = [
        'Super Admin',
        'Project Manager',
        'Project Officer',
        'Manager',
        'Employee',
        'Client Service'
    ];

    // Enquiry number prefix
    const ENQUIRY_PREFIX = 'WNG';

    // Project ID prefix
    const PROJECT_PREFIX = 'WNG';

    // Pagination default
    const PAGINATION_PER_PAGE = 6;

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
            self::STATUS_PLANNING,
            self::STATUS_IN_PROGRESS,
        ];
    }
}
