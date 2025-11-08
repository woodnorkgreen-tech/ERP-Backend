<?php

namespace App\Constants;

/**
 * Permission constants for the entire application
 * Centralizes all permission definitions for consistency
 */
class Permissions
{
    // ===========================================
    // USER MANAGEMENT PERMISSIONS
    // ===========================================
    const USER_CREATE = 'user.create';
    const USER_READ = 'user.read';
    const USER_UPDATE = 'user.update';
    const USER_DELETE = 'user.delete';
    const USER_ASSIGN_ROLE = 'user.assign_role';
    const USER_ASSIGN_DEPARTMENT = 'user.assign_department';
    const USER_ACTIVATE = 'user.activate';
    const USER_DEACTIVATE = 'user.deactivate';

    // ===========================================
    // ROLE MANAGEMENT PERMISSIONS
    // ===========================================
    const ROLE_CREATE = 'role.create';
    const ROLE_READ = 'role.read';
    const ROLE_UPDATE = 'role.update';
    const ROLE_DELETE = 'role.delete';
    const ROLE_ASSIGN_PERMISSION = 'role.assign_permission';

    // ===========================================
    // DEPARTMENT MANAGEMENT PERMISSIONS
    // ===========================================
    const DEPARTMENT_CREATE = 'department.create';
    const DEPARTMENT_READ = 'department.read';
    const DEPARTMENT_UPDATE = 'department.update';
    const DEPARTMENT_DELETE = 'department.delete';
    const DEPARTMENT_ACCESS = 'department.access';
    const DEPARTMENT_MANAGE = 'department.manage';

    // ===========================================
    // EMPLOYEE MANAGEMENT PERMISSIONS (HR)
    // ===========================================
    const EMPLOYEE_CREATE = 'employee.create';
    const EMPLOYEE_READ = 'employee.read';
    const EMPLOYEE_UPDATE = 'employee.update';
    const EMPLOYEE_DELETE = 'employee.delete';

    // ===========================================
    // PROJECT MANAGEMENT PERMISSIONS
    // ===========================================
    const PROJECT_CREATE = 'project.create';
    const PROJECT_READ = 'project.read';
    const PROJECT_UPDATE = 'project.update';
    const PROJECT_DELETE = 'project.delete';
    const PROJECT_ASSIGN_USERS = 'project.assign_users';
    const PROJECT_VIEW_REPORTS = 'project.view_reports';
    const PROJECT_CLOSE = 'project.close';

    // ===========================================
    // ENQUIRY MANAGEMENT PERMISSIONS
    // ===========================================
    const ENQUIRY_CREATE = 'enquiry.create';
    const ENQUIRY_READ = 'enquiry.read';
    const ENQUIRY_UPDATE = 'enquiry.update';
    const ENQUIRY_DELETE = 'enquiry.delete';
    const ENQUIRY_CONVERT = 'enquiry.convert';
    const ENQUIRY_ASSIGN = 'enquiry.assign';

    // ===========================================
    // FINANCE PERMISSIONS (Granular)
    // ===========================================
    const FINANCE_VIEW = 'finance.view';
    const FINANCE_BUDGET_CREATE = 'finance.budget.create';
    const FINANCE_BUDGET_READ = 'finance.budget.read';
    const FINANCE_BUDGET_UPDATE = 'finance.budget.update';
    const FINANCE_BUDGET_APPROVE = 'finance.budget.approve';
    const FINANCE_BUDGET_DELETE = 'finance.budget.delete';

    const FINANCE_QUOTE_CREATE = 'finance.quote.create';
    const FINANCE_QUOTE_READ = 'finance.quote.read';
    const FINANCE_QUOTE_UPDATE = 'finance.quote.update';
    const FINANCE_QUOTE_APPROVE = 'finance.quote.approve';
    const FINANCE_QUOTE_DELETE = 'finance.quote.delete';

    const FINANCE_INVOICE_CREATE = 'finance.invoice.create';
    const FINANCE_INVOICE_READ = 'finance.invoice.read';
    const FINANCE_INVOICE_UPDATE = 'finance.invoice.update';
    const FINANCE_INVOICE_DELETE = 'finance.invoice.delete';

    const FINANCE_REPORTS_VIEW = 'finance.reports.view';
    const FINANCE_ANALYTICS_VIEW = 'finance.analytics.view';

    const FINANCE_PETTY_CASH_VIEW = 'finance.petty_cash.view';
    const FINANCE_PETTY_CASH_CREATE = 'finance.petty_cash.create';
    const FINANCE_PETTY_CASH_UPDATE = 'finance.petty_cash.update';
    const FINANCE_PETTY_CASH_VOID = 'finance.petty_cash.void';
    const FINANCE_PETTY_CASH_CREATE_TOP_UP = 'finance.petty_cash.create_top_up';
    const FINANCE_PETTY_CASH_ADMIN = 'finance.petty_cash.admin';

    // ===========================================
    // HR PERMISSIONS
    // ===========================================
    const HR_VIEW_EMPLOYEES = 'hr.view_employees';
    const HR_MANAGE_PAYROLL = 'hr.manage_payroll';
    const HR_CREATE_POSITION = 'hr.create_position';
    const HR_MANAGE_ATTENDANCE = 'hr.manage_attendance';

    // ===========================================
    // CREATIVES/DESIGN PERMISSIONS
    // ===========================================
    const CREATIVES_VIEW = 'creatives.view';
    const CREATIVES_DESIGN_CREATE = 'creatives.design.create';
    const CREATIVES_DESIGN_UPDATE = 'creatives.design.update';
    const CREATIVES_DESIGN_APPROVE = 'creatives.design.approve';
    const CREATIVES_MATERIALS_MANAGE = 'creatives.materials.manage';

    // ===========================================
    // CLIENT SERVICE PERMISSIONS
    // ===========================================
    const CLIENT_CREATE = 'client.create';
    const CLIENT_READ = 'client.read';
    const CLIENT_UPDATE = 'client.update';
    const CLIENT_DELETE = 'client.delete';

    // ===========================================
    // PROCUREMENT PERMISSIONS
    // ===========================================
    const PROCUREMENT_VIEW = 'procurement.view';
    const PROCUREMENT_MATERIALS_REQUEST = 'procurement.materials.request';
    const PROCUREMENT_ORDERS_CREATE = 'procurement.orders.create';
    const PROCUREMENT_VENDORS_MANAGE = 'procurement.vendors.manage';
    const PROCUREMENT_QUOTATIONS_MANAGE = 'procurement.quotations.manage';

    // ===========================================
    // SYSTEM ADMIN PERMISSIONS
    // ===========================================
    const ADMIN_ACCESS = 'admin.access';
    const ADMIN_LOGS_VIEW = 'admin.logs.view';
    const ADMIN_SETTINGS = 'admin.settings';
    const ADMIN_BACKUP = 'admin.backup';
    const ADMIN_MAINTENANCE = 'admin.maintenance';

    // ===========================================
    // TASK MANAGEMENT PERMISSIONS
    // ===========================================
    const TASK_CREATE = 'task.create';
    const TASK_READ = 'task.read';
    const TASK_UPDATE = 'task.update';
    const TASK_DELETE = 'task.delete';
    const TASK_ASSIGN = 'task.assign';
    const TASK_COMPLETE = 'task.complete';
    const TASK_SKIP = 'task.skip';

    // ===========================================
    // DASHBOARD PERMISSIONS
    // ===========================================
    const DASHBOARD_VIEW = 'dashboard.view';
    const DASHBOARD_ADMIN = 'dashboard.admin';
    const DASHBOARD_HR = 'dashboard.hr';
    const DASHBOARD_FINANCE = 'dashboard.finance';
    const DASHBOARD_PROJECTS = 'dashboard.projects';

    /**
     * Get all permission constants as an array
     */
    public static function all(): array
    {
        return [
            // User Management
            self::USER_CREATE, self::USER_READ, self::USER_UPDATE, self::USER_DELETE,
            self::USER_ASSIGN_ROLE, self::USER_ASSIGN_DEPARTMENT, self::USER_ACTIVATE, self::USER_DEACTIVATE,

            // Role Management
            self::ROLE_CREATE, self::ROLE_READ, self::ROLE_UPDATE, self::ROLE_DELETE, self::ROLE_ASSIGN_PERMISSION,

            // Department Management
            self::DEPARTMENT_CREATE, self::DEPARTMENT_READ, self::DEPARTMENT_UPDATE, self::DEPARTMENT_DELETE,
            self::DEPARTMENT_ACCESS, self::DEPARTMENT_MANAGE,

            // Employee Management
            self::EMPLOYEE_CREATE, self::EMPLOYEE_READ, self::EMPLOYEE_UPDATE, self::EMPLOYEE_DELETE,

            // Project Management
            self::PROJECT_CREATE, self::PROJECT_READ, self::PROJECT_UPDATE, self::PROJECT_DELETE,
            self::PROJECT_ASSIGN_USERS, self::PROJECT_VIEW_REPORTS, self::PROJECT_CLOSE,

            // Enquiry Management
            self::ENQUIRY_CREATE, self::ENQUIRY_READ, self::ENQUIRY_UPDATE, self::ENQUIRY_DELETE,
            self::ENQUIRY_CONVERT, self::ENQUIRY_ASSIGN,

            // Finance Permissions
            self::FINANCE_VIEW, self::FINANCE_BUDGET_CREATE, self::FINANCE_BUDGET_READ,
            self::FINANCE_BUDGET_UPDATE, self::FINANCE_BUDGET_APPROVE, self::FINANCE_BUDGET_DELETE,
            self::FINANCE_QUOTE_CREATE, self::FINANCE_QUOTE_READ, self::FINANCE_QUOTE_UPDATE,
            self::FINANCE_QUOTE_APPROVE, self::FINANCE_QUOTE_DELETE, self::FINANCE_INVOICE_CREATE,
            self::FINANCE_INVOICE_READ, self::FINANCE_INVOICE_UPDATE, self::FINANCE_INVOICE_DELETE,
            self::FINANCE_REPORTS_VIEW, self::FINANCE_ANALYTICS_VIEW,
            self::FINANCE_PETTY_CASH_VIEW, self::FINANCE_PETTY_CASH_CREATE, self::FINANCE_PETTY_CASH_UPDATE,
            self::FINANCE_PETTY_CASH_VOID, self::FINANCE_PETTY_CASH_CREATE_TOP_UP, self::FINANCE_PETTY_CASH_ADMIN,

            // HR Permissions
            self::HR_VIEW_EMPLOYEES, self::HR_MANAGE_PAYROLL, self::HR_CREATE_POSITION, self::HR_MANAGE_ATTENDANCE,

            // Creatives Permissions
            self::CREATIVES_VIEW, self::CREATIVES_DESIGN_CREATE, self::CREATIVES_DESIGN_UPDATE,
            self::CREATIVES_DESIGN_APPROVE, self::CREATIVES_MATERIALS_MANAGE,

            // Client Service Permissions
            self::CLIENT_CREATE, self::CLIENT_READ, self::CLIENT_UPDATE, self::CLIENT_DELETE,

            // Procurement Permissions
            self::PROCUREMENT_VIEW, self::PROCUREMENT_MATERIALS_REQUEST, self::PROCUREMENT_ORDERS_CREATE,
            self::PROCUREMENT_VENDORS_MANAGE, self::PROCUREMENT_QUOTATIONS_MANAGE,

            // System Admin Permissions
            self::ADMIN_ACCESS, self::ADMIN_LOGS_VIEW, self::ADMIN_SETTINGS, self::ADMIN_BACKUP, self::ADMIN_MAINTENANCE,

            // Task Management
            self::TASK_CREATE, self::TASK_READ, self::TASK_UPDATE, self::TASK_DELETE,
            self::TASK_ASSIGN, self::TASK_COMPLETE, self::TASK_SKIP,

            // Dashboard Permissions
            self::DASHBOARD_VIEW, self::DASHBOARD_ADMIN, self::DASHBOARD_HR, self::DASHBOARD_FINANCE, self::DASHBOARD_PROJECTS,
        ];
    }

    /**
     * Get permissions grouped by module
     */
    public static function grouped(): array
    {
        return [
            'user_management' => [
                self::USER_CREATE, self::USER_READ, self::USER_UPDATE, self::USER_DELETE,
                self::USER_ASSIGN_ROLE, self::USER_ASSIGN_DEPARTMENT, self::USER_ACTIVATE, self::USER_DEACTIVATE,
            ],
            'role_management' => [
                self::ROLE_CREATE, self::ROLE_READ, self::ROLE_UPDATE, self::ROLE_DELETE, self::ROLE_ASSIGN_PERMISSION,
            ],
            'department_management' => [
                self::DEPARTMENT_CREATE, self::DEPARTMENT_READ, self::DEPARTMENT_UPDATE, self::DEPARTMENT_DELETE,
                self::DEPARTMENT_ACCESS, self::DEPARTMENT_MANAGE,
            ],
            'employee_management' => [
                self::EMPLOYEE_CREATE, self::EMPLOYEE_READ, self::EMPLOYEE_UPDATE, self::EMPLOYEE_DELETE,
            ],
            'project_management' => [
                self::PROJECT_CREATE, self::PROJECT_READ, self::PROJECT_UPDATE, self::PROJECT_DELETE,
                self::PROJECT_ASSIGN_USERS, self::PROJECT_VIEW_REPORTS, self::PROJECT_CLOSE,
            ],
            'enquiry_management' => [
                self::ENQUIRY_CREATE, self::ENQUIRY_READ, self::ENQUIRY_UPDATE, self::ENQUIRY_DELETE,
                self::ENQUIRY_CONVERT, self::ENQUIRY_ASSIGN,
            ],
            'finance' => [
                self::FINANCE_VIEW, self::FINANCE_BUDGET_CREATE, self::FINANCE_BUDGET_READ,
                self::FINANCE_BUDGET_UPDATE, self::FINANCE_BUDGET_APPROVE, self::FINANCE_BUDGET_DELETE,
                self::FINANCE_QUOTE_CREATE, self::FINANCE_QUOTE_READ, self::FINANCE_QUOTE_UPDATE,
                self::FINANCE_QUOTE_APPROVE, self::FINANCE_QUOTE_DELETE, self::FINANCE_INVOICE_CREATE,
                self::FINANCE_INVOICE_READ, self::FINANCE_INVOICE_UPDATE, self::FINANCE_INVOICE_DELETE,
                self::FINANCE_REPORTS_VIEW, self::FINANCE_ANALYTICS_VIEW,
                self::FINANCE_PETTY_CASH_VIEW, self::FINANCE_PETTY_CASH_CREATE, self::FINANCE_PETTY_CASH_UPDATE,
                self::FINANCE_PETTY_CASH_VOID, self::FINANCE_PETTY_CASH_CREATE_TOP_UP, self::FINANCE_PETTY_CASH_ADMIN,
            ],
            'hr' => [
                self::HR_VIEW_EMPLOYEES, self::HR_MANAGE_PAYROLL, self::HR_CREATE_POSITION, self::HR_MANAGE_ATTENDANCE,
            ],
            'creatives' => [
                self::CREATIVES_VIEW, self::CREATIVES_DESIGN_CREATE, self::CREATIVES_DESIGN_UPDATE,
                self::CREATIVES_DESIGN_APPROVE, self::CREATIVES_MATERIALS_MANAGE,
            ],
            'client_service' => [
                self::CLIENT_CREATE, self::CLIENT_READ, self::CLIENT_UPDATE, self::CLIENT_DELETE,
            ],
            'procurement' => [
                self::PROCUREMENT_VIEW, self::PROCUREMENT_MATERIALS_REQUEST, self::PROCUREMENT_ORDERS_CREATE,
                self::PROCUREMENT_VENDORS_MANAGE, self::PROCUREMENT_QUOTATIONS_MANAGE,
            ],
            'admin' => [
                self::ADMIN_ACCESS, self::ADMIN_LOGS_VIEW, self::ADMIN_SETTINGS, self::ADMIN_BACKUP, self::ADMIN_MAINTENANCE,
            ],
            'tasks' => [
                self::TASK_CREATE, self::TASK_READ, self::TASK_UPDATE, self::TASK_DELETE,
                self::TASK_ASSIGN, self::TASK_COMPLETE, self::TASK_SKIP,
            ],
            'dashboard' => [
                self::DASHBOARD_VIEW, self::DASHBOARD_ADMIN, self::DASHBOARD_HR, self::DASHBOARD_FINANCE, self::DASHBOARD_PROJECTS,
            ],
        ];
    }
}
