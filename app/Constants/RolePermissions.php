<?php

namespace App\Constants;

/**
 * Which role holds which permission.
 *
 * Allocation used to live in three places that could disagree: the seeder's
 * hand-written lists, fifteen one-off migrations, and whatever a given database
 * happened to have been through. They did disagree - nine of those migrations
 * granted to role names that have never existed, and because
 * `Role::whereIn(...)->get()->each(...)` skips a name it cannot find, every
 * one of them reported success while granting nothing. That is how the
 * Requisition Types screen ended up reachable only by a Super Admin.
 *
 * This is now the single source of truth. The seeder builds from it and
 * `permissions:sync` reconciles a live database to it. Granting a
 * permission is an edit here, not another migration.
 *
 * @see \App\Console\Commands\SyncPermissionsCommand
 */
final class RolePermissions
{
    /**
     * Every role that exists.
     *
     * A name outside this list is a typo rather than a role, so
     * `permissions:sync` refuses to run rather than skipping it quietly -
     * the silent skip is the whole reason this file exists.
     */
    public const ROLES = [
        'Super Admin', 'Admin', 'Manager', 'Accounts', 'Costing', 'HR', 'Client Service',
        'Project Manager', 'Project Officer', 'Production', 'Procurement', 'Stores', 'Logistics',
        'Designer', 'Employee',
    ];

    /**
     * Names that appeared in migrations but were never roles.
     *
     * Kept as documentation of what those migrations were reaching for, so a
     * reviewer meeting "Finance Manager" in the history can see where its
     * authority actually went. Their grants were folded onto the mapped role
     * only where the tier held no real finance role to begin with. Where a
     * migration scoped a real role deliberately narrower in the same breath -
     * Accounts being denied receivables.override - that narrower scope won.
     */
    public const RETIRED_ROLE_ALIASES = [
        'Finance' => 'Accounts',
        'Finance Manager' => 'Accounts',
        'Accountant' => 'Accounts',
        'Projects' => 'Project Officer',
        'Logistics Officer' => 'Logistics',
        'Logistics Manager' => 'Logistics',
    ];

    /**
     * What each role is for.
     *
     * Held beside the matrix so a role's identity and its authority are read
     * and edited together, rather than the name living in the seeder and the
     * grants living somewhere else.
     */
    public const DESCRIPTIONS = [
        'Super Admin' => 'Full system access',
        'Admin' => 'Administrative access',
        'Manager' => 'Department management',
        'Accounts' => 'Financial accounting and invoicing',
        'Costing' => 'Cost analysis and budget management',
        'HR' => 'Human Resources access',
        'Client Service' => 'Client acquisition and enquiry management',
        'Project Manager' => 'Project management and coordination',
        'Project Officer' => 'Project coordination support',
        'Production' => 'Production and manufacturing operations',
        'Procurement' => 'Procurement and sourcing operations',
        'Stores' => 'Inventory and stores management',
        'Logistics' => 'Logistics and delivery coordination',
        'Designer' => 'Creative design and development',
        'Employee' => 'Basic employee access',
    ];

    /** @return array<string, list<string>> */
    public static function matrix(): array
    {
        return [
            // Super Admin is the whole registry by construction, never a list to maintain.
            'Super Admin' => Permissions::all(),
            'Admin' => [
                Permissions::ADMIN_ACCESS, Permissions::CLIENT_HANDOVER_REVIEW,
                Permissions::DASHBOARD_ADMIN, Permissions::DEPARTMENT_READ, Permissions::DEPARTMENT_UPDATE,
                Permissions::EMPLOYEE_READ, Permissions::FINANCE_COSTS_CREATE,
                Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_COSTS_REVERSE,
                Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_PETTY_CASH_APPROVE_OFFLINE_BATCH,
                Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_PETTY_CASH_CREATE_TOP_UP,
                Permissions::FINANCE_PETTY_CASH_DELETE, Permissions::FINANCE_PETTY_CASH_UPDATE,
                Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL, Permissions::FINANCE_PETTY_CASH_VIEW,
                Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
                Permissions::FINANCE_PETTY_CASH_VOID, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
                Permissions::FINANCE_RECEIVABLES_CORRECT, Permissions::FINANCE_RECEIVABLES_OVERRIDE,
                Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD,
                Permissions::FINANCE_RECEIVABLES_RELEASE, Permissions::FINANCE_RECEIVABLES_REVERSE,
                Permissions::FINANCE_RECEIVABLES_VERIFY, Permissions::FINANCE_SPEND_VOUCHERS_APPROVE,
                Permissions::FINANCE_SPEND_VOUCHERS_CREATE, Permissions::FINANCE_SPEND_VOUCHERS_POST,
                Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_VIEW,
                Permissions::COMPENSATION_APPROVE, Permissions::COMPENSATION_READ,
                Permissions::OVERTIME_APPROVE_HR, Permissions::OVERTIME_READ,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::LEAVE_REQUEST_APPROVE,
                Permissions::LEAVE_REQUEST_CREATE, Permissions::LEAVE_REQUEST_DELETE,
                Permissions::LEAVE_REQUEST_READ, Permissions::LEAVE_REQUEST_UPDATE,
                Permissions::LEAVE_TYPE_CREATE, Permissions::LEAVE_TYPE_DELETE,
                Permissions::LEAVE_TYPE_READ, Permissions::LEAVE_TYPE_UPDATE,
                Permissions::LOGISTICS_DELIVERIES_MANAGE, Permissions::LOGISTICS_DRIVERS_MANAGE,
                Permissions::LOGISTICS_FLEET_MANAGE, Permissions::LOGISTICS_ROUTES_MANAGE,
                Permissions::LOGISTICS_TRACKING_VIEW, Permissions::LOGISTICS_VIEW,
                Permissions::PROJECT_COSTS_READ_ASSIGNED, Permissions::PROJECT_READ, Permissions::ROLE_READ,
                Permissions::SUPPORT_MANAGE, Permissions::USER_ASSIGN_ROLE, Permissions::USER_CREATE,
                Permissions::USER_READ, Permissions::USER_UPDATE,
            ],
            'Manager' => [
                Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_ACCESS, Permissions::DEPARTMENT_READ,
                Permissions::EMPLOYEE_READ, Permissions::FINANCE_PETTY_CASH_CREATE,
                Permissions::FINANCE_PETTY_CASH_DELETE, Permissions::FINANCE_PETTY_CASH_UPDATE,
                Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL, Permissions::FINANCE_PETTY_CASH_VIEW,
                Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
                Permissions::FINANCE_PETTY_CASH_VOID, Permissions::COMPENSATION_READ,
                Permissions::OVERTIME_APPROVE_SUPERVISOR, Permissions::OVERTIME_READ,
                Permissions::LEAVE_REQUEST_APPROVE, Permissions::LEAVE_REQUEST_READ,
                Permissions::MATERIALS_LIBRARY_IMPORT, Permissions::MATERIALS_LIBRARY_MANAGE,
                Permissions::MATERIALS_LIBRARY_VIEW, Permissions::PROJECT_ASSIGN_USERS,
                Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE, Permissions::STORES_MANAGE,
                Permissions::STORES_REVIEW, Permissions::STORES_VIEW, Permissions::TASK_ASSIGN,
                Permissions::TASK_READ, Permissions::TASK_UPDATE, Permissions::USER_READ,
                Permissions::USER_UPDATE,
            ],
            'Accounts' => [
                Permissions::DASHBOARD_FINANCE, Permissions::FINANCE_BUDGET_READ,
                Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
                Permissions::FINANCE_COSTS_REVERSE, Permissions::FINANCE_COSTS_VERIFY,
                Permissions::FINANCE_PETTY_CASH_ADMIN,
                Permissions::FINANCE_PETTY_CASH_APPROVE_OFFLINE_BATCH,
                Permissions::FINANCE_PETTY_CASH_CREATE_LEGACY, Permissions::FINANCE_PETTY_CASH_CREATE,
                Permissions::FINANCE_PETTY_CASH_CREATE_TOP_UP, Permissions::FINANCE_PETTY_CASH_DELETE,
                Permissions::FINANCE_PETTY_CASH_UPDATE, Permissions::FINANCE_PETTY_CASH_UPDATE_LEGACY,
                Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL, Permissions::FINANCE_PETTY_CASH_VIEW,
                Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
                Permissions::FINANCE_PETTY_CASH_VOID_LEGACY, Permissions::FINANCE_PETTY_CASH_VOID,
                Permissions::FINANCE_QUOTE_APPROVE, Permissions::FINANCE_RECEIVABLES_BILLING_BASIS,
                Permissions::FINANCE_RECEIVABLES_CORRECT, Permissions::FINANCE_RECEIVABLES_READ,
                Permissions::FINANCE_RECEIVABLES_RECORD, Permissions::FINANCE_RECEIVABLES_RELEASE,
                Permissions::FINANCE_RECEIVABLES_REVERSE, Permissions::FINANCE_RECEIVABLES_VERIFY,
                Permissions::FINANCE_REQUISITION_TYPES_MANAGE, Permissions::FINANCE_SPEND_VOUCHERS_APPROVE,
                Permissions::FINANCE_SPEND_VOUCHERS_CREATE, Permissions::FINANCE_SPEND_VOUCHERS_POST,
                Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_VIEW,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::PROJECT_COSTS_READ_ASSIGNED,
                Permissions::PROJECT_READ, Permissions::USER_READ,
            ],
            'Costing' => [
                Permissions::DASHBOARD_FINANCE, Permissions::FINANCE_BUDGET_APPROVE,
                Permissions::FINANCE_BUDGET_READ, Permissions::FINANCE_BUDGET_UPDATE,
                Permissions::FINANCE_EXPENSE_CODES_MANAGE, Permissions::FINANCE_QUOTE_APPROVE,
                Permissions::FINANCE_QUOTE_CREATE, Permissions::FINANCE_QUOTE_READ,
                Permissions::FINANCE_QUOTE_UPDATE, Permissions::FINANCE_RECEIVABLES_READ,
                Permissions::FINANCE_REQUISITION_TYPES_MANAGE, Permissions::FINANCE_VIEW,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::PROJECT_COSTS_READ_ASSIGNED,
                Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE, Permissions::TASK_READ,
                Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'HR' => [
                Permissions::DASHBOARD_HR, Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_READ,
                Permissions::EMPLOYEE_CREATE, Permissions::EMPLOYEE_DELETE, Permissions::EMPLOYEE_READ,
                Permissions::EMPLOYEE_UPDATE, Permissions::ENQUIRY_ASSIGN, Permissions::ENQUIRY_CONVERT,
                Permissions::ENQUIRY_CREATE, Permissions::ENQUIRY_DELETE, Permissions::ENQUIRY_READ,
                Permissions::ENQUIRY_UPDATE, Permissions::COMPENSATION_APPROVE,
                Permissions::COMPENSATION_READ, Permissions::HR_CREATE_POSITION,
                Permissions::HR_MANAGE_ATTENDANCE, Permissions::HR_MANAGE_PAYROLL,
                Permissions::OVERTIME_APPROVE_HR, Permissions::OVERTIME_MANAGE_FLAGS,
                Permissions::OVERTIME_READ, Permissions::HR_VIEW_EMPLOYEES,
                Permissions::LEAVE_REQUEST_APPROVE, Permissions::LEAVE_REQUEST_CREATE,
                Permissions::LEAVE_REQUEST_DELETE, Permissions::LEAVE_REQUEST_READ,
                Permissions::LEAVE_REQUEST_UPDATE, Permissions::LEAVE_TYPE_CREATE,
                Permissions::LEAVE_TYPE_DELETE, Permissions::LEAVE_TYPE_READ,
                Permissions::LEAVE_TYPE_UPDATE, Permissions::OFFBOARDING_APPROVE,
                Permissions::OFFBOARDING_CLEARANCE, Permissions::OFFBOARDING_CREATE,
                Permissions::OFFBOARDING_MANAGE, Permissions::OFFBOARDING_SETTLEMENT,
                Permissions::OFFBOARDING_VIEW, Permissions::PROJECT_ASSIGN_USERS,
                Permissions::PROJECT_CLOSE, Permissions::PROJECT_CREATE, Permissions::PROJECT_DELETE,
                Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE, Permissions::PROJECT_VIEW_REPORTS,
                Permissions::TASK_READ, Permissions::USER_ASSIGN_ROLE, Permissions::USER_READ,
                Permissions::USER_UPDATE,
            ],
            'Client Service' => [
                Permissions::CLIENT_CREATE, Permissions::CLIENT_DELETE, Permissions::CLIENT_HANDOVER_REVIEW,
                Permissions::CLIENT_READ, Permissions::CLIENT_UPDATE, Permissions::DASHBOARD_PROJECTS,
                Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_READ, Permissions::EMPLOYEE_READ,
                Permissions::ENQUIRY_CREATE, Permissions::ENQUIRY_READ, Permissions::ENQUIRY_UPDATE,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::PROJECT_ASSIGN_USERS,
                Permissions::PROJECT_CREATE, Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE,
                Permissions::PROJECT_VIEW_REPORTS, Permissions::TASK_ASSIGN, Permissions::TASK_READ,
                Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Project Manager' => [
                Permissions::CLIENT_CREATE, Permissions::CLIENT_DELETE, Permissions::CLIENT_READ,
                Permissions::CLIENT_UPDATE, Permissions::DASHBOARD_PROJECTS, Permissions::DEPARTMENT_READ,
                Permissions::ENQUIRY_READ, Permissions::ENQUIRY_UPDATE, Permissions::FINANCE_BUDGET_APPROVE,
                Permissions::FINANCE_QUOTE_APPROVE, Permissions::FINANCE_QUOTE_CREATE,
                Permissions::FINANCE_QUOTE_READ, Permissions::FINANCE_QUOTE_UPDATE,
                Permissions::FINANCE_RECEIVABLES_READ, Permissions::PROJECT_ASSIGN_USERS,
                Permissions::PROJECT_COSTS_READ_ASSIGNED, Permissions::PROJECT_CREATE,
                Permissions::PROJECT_DELETE, Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE,
                Permissions::TASK_ASSIGN, Permissions::TASK_CREATE, Permissions::TASK_READ,
                Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Project Officer' => [
                Permissions::DASHBOARD_PROJECTS, Permissions::DEPARTMENT_READ, Permissions::ENQUIRY_CREATE,
                Permissions::ENQUIRY_READ, Permissions::FINANCE_COSTS_CREATE,
                Permissions::FINANCE_COSTS_READ, Permissions::PROJECT_ASSIGN_USERS,
                Permissions::PROJECT_COSTS_READ_ASSIGNED, Permissions::PROJECT_READ,
                Permissions::PROJECT_UPDATE, Permissions::TASK_ASSIGN, Permissions::TASK_READ,
                Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Production' => [
                Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_READ,
                Permissions::FINANCE_BUDGET_APPROVE, Permissions::FINANCE_BUDGET_CREATE,
                Permissions::FINANCE_BUDGET_DELETE, Permissions::FINANCE_BUDGET_READ,
                Permissions::FINANCE_BUDGET_UPDATE, Permissions::FINANCE_COSTS_CREATE,
                Permissions::FINANCE_COSTS_READ, Permissions::FINANCE_QUOTE_APPROVE,
                Permissions::FINANCE_QUOTE_CREATE, Permissions::FINANCE_QUOTE_READ,
                Permissions::FINANCE_QUOTE_UPDATE, Permissions::MATERIALS_LIBRARY_VIEW,
                Permissions::PROJECT_READ, Permissions::STORES_VIEW, Permissions::TASK_READ,
                Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Procurement' => [
                Permissions::DASHBOARD_PROJECTS, Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_READ,
                Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::MATERIALS_LIBRARY_IMPORT,
                Permissions::MATERIALS_LIBRARY_MANAGE, Permissions::MATERIALS_LIBRARY_VIEW,
                Permissions::PROCUREMENT_MATERIALS_REQUEST, Permissions::PROCUREMENT_ORDERS_CREATE,
                Permissions::PROCUREMENT_QUOTATIONS_MANAGE, Permissions::PROCUREMENT_VENDORS_MANAGE,
                Permissions::PROCUREMENT_VIEW, Permissions::PROJECT_READ, Permissions::STORES_VIEW,
                Permissions::TASK_READ, Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Stores' => [
                Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_READ,
                Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::MATERIALS_LIBRARY_IMPORT,
                Permissions::MATERIALS_LIBRARY_MANAGE, Permissions::MATERIALS_LIBRARY_VIEW,
                Permissions::PROJECT_READ, Permissions::STORES_MANAGE, Permissions::STORES_VIEW,
                Permissions::TASK_READ, Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Logistics' => [
                Permissions::DASHBOARD_VIEW, Permissions::DEPARTMENT_READ,
                Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::LOGISTICS_DELIVERIES_MANAGE,
                Permissions::LOGISTICS_DRIVERS_MANAGE, Permissions::LOGISTICS_FLEET_MANAGE,
                Permissions::LOGISTICS_ROUTES_MANAGE, Permissions::LOGISTICS_TRACKING_VIEW,
                Permissions::LOGISTICS_VIEW, Permissions::PROJECT_READ, Permissions::TASK_READ,
                Permissions::TASK_UPDATE, Permissions::USER_READ,
            ],
            'Designer' => [
                Permissions::CREATIVES_VIEW, Permissions::DASHBOARD_PROJECTS, Permissions::DASHBOARD_VIEW,
                Permissions::DEPARTMENT_READ, Permissions::PROJECT_READ, Permissions::TASK_READ,
                Permissions::TASK_UPDATE,
            ],
            'Employee' => [
                Permissions::DASHBOARD_VIEW, Permissions::COMPENSATION_CREATE,
                Permissions::COMPENSATION_READ, Permissions::OVERTIME_CREATE, Permissions::OVERTIME_READ,
                Permissions::HR_VIEW_EMPLOYEES, Permissions::LEAVE_REQUEST_CREATE,
                Permissions::LEAVE_REQUEST_READ, Permissions::LEAVE_REQUEST_UPDATE,
                Permissions::PROJECT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
                Permissions::USER_READ,
            ],
        ];
    }
}
