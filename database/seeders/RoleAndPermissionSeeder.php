<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Constants\Permissions;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create all permissions from constants
        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions using constants
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin'], ['description' => 'Full system access']);
        $superAdminRole->givePermissionTo(Permission::all());

        $adminRole = Role::firstOrCreate(['name' => 'Admin'], ['description' => 'Administrative access']);
        $adminRole->givePermissionTo([
            Permissions::USER_CREATE, Permissions::USER_READ, Permissions::USER_UPDATE,
            Permissions::USER_ASSIGN_ROLE,
            Permissions::ROLE_READ, Permissions::DEPARTMENT_READ, Permissions::DEPARTMENT_UPDATE,
            Permissions::EMPLOYEE_READ, Permissions::PROJECT_READ, Permissions::FINANCE_VIEW,
            Permissions::LEAVE_TYPE_CREATE, Permissions::LEAVE_TYPE_READ, Permissions::LEAVE_TYPE_UPDATE,
            Permissions::LEAVE_TYPE_DELETE, Permissions::LEAVE_REQUEST_CREATE, Permissions::LEAVE_REQUEST_READ,
            Permissions::LEAVE_REQUEST_UPDATE, Permissions::LEAVE_REQUEST_APPROVE, Permissions::LEAVE_REQUEST_DELETE,
            Permissions::FINANCE_PETTY_CASH_VIEW,
            Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE,
            Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
            Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::FINANCE_PETTY_CASH_UPDATE, Permissions::FINANCE_PETTY_CASH_VOID,
            Permissions::FINANCE_PETTY_CASH_DELETE, Permissions::FINANCE_PETTY_CASH_CREATE_TOP_UP,
            Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL,
            Permissions::HR_VIEW_EMPLOYEES, Permissions::ADMIN_ACCESS, Permissions::DASHBOARD_ADMIN,
            Permissions::OVERTIME_READ, Permissions::OVERTIME_APPROVE_HR, Permissions::COMPENSATION_READ, Permissions::COMPENSATION_APPROVE
        ]);

        $hrRole = Role::firstOrCreate(['name' => 'HR'], ['description' => 'Human Resources access']);
        $hrRole->givePermissionTo([
            Permissions::EMPLOYEE_CREATE, Permissions::EMPLOYEE_READ, Permissions::EMPLOYEE_UPDATE,
            Permissions::EMPLOYEE_DELETE, Permissions::USER_READ, Permissions::USER_UPDATE,
            Permissions::DEPARTMENT_READ, Permissions::HR_VIEW_EMPLOYEES, Permissions::DASHBOARD_HR,
            Permissions::LEAVE_TYPE_CREATE, Permissions::LEAVE_TYPE_READ, Permissions::LEAVE_TYPE_UPDATE,
            Permissions::LEAVE_TYPE_DELETE, Permissions::LEAVE_REQUEST_CREATE, Permissions::LEAVE_REQUEST_READ,
            Permissions::LEAVE_REQUEST_UPDATE, Permissions::LEAVE_REQUEST_APPROVE, Permissions::LEAVE_REQUEST_DELETE,
            Permissions::OVERTIME_READ, Permissions::OVERTIME_APPROVE_HR, Permissions::OVERTIME_MANAGE_FLAGS,
            Permissions::COMPENSATION_READ, Permissions::COMPENSATION_APPROVE,
            Permissions::OFFBOARDING_VIEW, Permissions::OFFBOARDING_CREATE, Permissions::OFFBOARDING_MANAGE,
            Permissions::OFFBOARDING_APPROVE, Permissions::OFFBOARDING_CLEARANCE, Permissions::OFFBOARDING_SETTLEMENT
        ]);

        $clientServiceRole = Role::firstOrCreate(['name' => 'Client Service'], ['description' => 'Client acquisition and enquiry management']);
        $clientServiceRole->givePermissionTo([
            // User & Department
            Permissions::USER_READ, Permissions::DEPARTMENT_READ,
            // Client Management
            Permissions::CLIENT_CREATE, Permissions::CLIENT_READ,
            // Enquiry Management
            Permissions::ENQUIRY_CREATE, Permissions::ENQUIRY_READ, Permissions::ENQUIRY_UPDATE,
            // Project & Task Access (same as Project Officer)
            Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE, Permissions::PROJECT_ASSIGN_USERS,
            Permissions::TASK_READ, Permissions::TASK_UPDATE, Permissions::TASK_ASSIGN,
            // Handover Review (CS Lead action — approve or trigger NCR)
            Permissions::CLIENT_HANDOVER_REVIEW,
            // Dashboard
            Permissions::DASHBOARD_VIEW, Permissions::DASHBOARD_PROJECTS
        ]);

        $managerRole = Role::firstOrCreate(['name' => 'Manager'], ['description' => 'Department management']);
        $managerRole->givePermissionTo([
            Permissions::USER_READ, Permissions::USER_UPDATE, Permissions::DEPARTMENT_READ,
            Permissions::DEPARTMENT_ACCESS, Permissions::EMPLOYEE_READ, Permissions::PROJECT_READ,
            Permissions::PROJECT_UPDATE, Permissions::PROJECT_ASSIGN_USERS, Permissions::TASK_READ,
            Permissions::TASK_UPDATE, Permissions::TASK_ASSIGN, Permissions::DASHBOARD_VIEW,
            Permissions::LEAVE_REQUEST_READ, Permissions::LEAVE_REQUEST_APPROVE,
            Permissions::FINANCE_PETTY_CASH_VIEW,
            Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE,
            Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
            Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::FINANCE_PETTY_CASH_UPDATE, Permissions::FINANCE_PETTY_CASH_VOID,
            Permissions::FINANCE_PETTY_CASH_DELETE, Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL,
            Permissions::OVERTIME_READ, Permissions::OVERTIME_APPROVE_SUPERVISOR, Permissions::COMPENSATION_READ
        ]);

        $employeeRole = Role::firstOrCreate(['name' => 'Employee'], ['description' => 'Basic employee access']);
        $employeeRole->givePermissionTo([
            Permissions::USER_READ, Permissions::PROJECT_READ, Permissions::TASK_READ,
            Permissions::TASK_UPDATE, Permissions::DASHBOARD_VIEW,
            Permissions::LEAVE_REQUEST_CREATE, Permissions::LEAVE_REQUEST_READ, Permissions::LEAVE_REQUEST_UPDATE,
            Permissions::OVERTIME_CREATE, Permissions::OVERTIME_READ, Permissions::COMPENSATION_CREATE, Permissions::COMPENSATION_READ
        ]);

        // Finance Roles
        $accountsRole = Role::firstOrCreate(['name' => 'Accounts'], ['description' => 'Financial accounting and invoicing']);
        $accountsRole->givePermissionTo([
            Permissions::FINANCE_VIEW, Permissions::FINANCE_BUDGET_READ,
            Permissions::FINANCE_QUOTE_APPROVE,
            Permissions::PROJECT_READ, Permissions::USER_READ,
            Permissions::DASHBOARD_FINANCE,
            Permissions::FINANCE_PETTY_CASH_VIEW,
            Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE,
            Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS,
            Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::FINANCE_PETTY_CASH_UPDATE, Permissions::FINANCE_PETTY_CASH_VOID,
            Permissions::FINANCE_PETTY_CASH_DELETE, Permissions::FINANCE_PETTY_CASH_CREATE_TOP_UP,
            Permissions::FINANCE_PETTY_CASH_UPLOAD_EXCEL
        ]);

        $costingRole = Role::firstOrCreate(['name' => 'Costing'], ['description' => 'Cost analysis and budget management']);
        $costingRole->givePermissionTo([
            Permissions::FINANCE_VIEW, Permissions::FINANCE_BUDGET_READ, Permissions::FINANCE_BUDGET_UPDATE,
            Permissions::FINANCE_QUOTE_APPROVE,
            Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE,
            Permissions::USER_READ, Permissions::DASHBOARD_FINANCE
        ]);

        // Creatives/Design Roles
        $designerRole = Role::firstOrCreate(['name' => 'Designer'], ['description' => 'Creative design and development']);
        $designerRole->givePermissionTo([
            Permissions::CREATIVES_VIEW,
            Permissions::DEPARTMENT_READ, Permissions::PROJECT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::DASHBOARD_VIEW
        ]);

        // Procurement Roles (Future - commented out until implemented)
        // $procurementRole = Role::firstOrCreate(['name' => 'Procurement Officer'], ['description' => 'Material sourcing and supplier management']);
        // $procurementRole->givePermissionTo([
        //     Permissions::PROCUREMENT_VIEW, Permissions::PROCUREMENT_MATERIALS_REQUEST,
        //     Permissions::PROCUREMENT_ORDERS_CREATE, Permissions::PROCUREMENT_VENDORS_MANAGE,
        //     Permissions::PROCUREMENT_QUOTATIONS_MANAGE, Permissions::DEPARTMENT_READ, Permissions::PROJECT_READ,
        //     Permissions::TASK_READ, Permissions::TASK_UPDATE,
        //     Permissions::DASHBOARD_VIEW
        // ]);

        // Project Roles
        $projectManagerRole = Role::firstOrCreate(['name' => 'Project Manager'], ['description' => 'Project management and coordination']);
        $projectManagerRole->givePermissionTo([
            Permissions::PROJECT_CREATE, Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE,
            Permissions::PROJECT_DELETE, Permissions::PROJECT_ASSIGN_USERS,
            Permissions::ENQUIRY_READ, Permissions::ENQUIRY_UPDATE,
            Permissions::DEPARTMENT_READ, Permissions::TASK_CREATE, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::TASK_ASSIGN, Permissions::USER_READ,
            Permissions::DASHBOARD_PROJECTS
        ]);

        $projectOfficerRole = Role::firstOrCreate(['name' => 'Project Officer'], ['description' => 'Project coordination support']);
        $projectOfficerRole->givePermissionTo([
            Permissions::PROJECT_READ, Permissions::PROJECT_UPDATE, Permissions::PROJECT_ASSIGN_USERS,
            Permissions::ENQUIRY_READ, Permissions::DEPARTMENT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::TASK_ASSIGN, Permissions::USER_READ, Permissions::DASHBOARD_PROJECTS
        ]);

        // Operational Roles (Production, Logistics, Stores, Procurement)
        // These roles have access to projects module until their dedicated modules are developed
        $productionRole = Role::firstOrCreate(['name' => 'Production'], ['description' => 'Production and manufacturing operations']);
        $productionRole->givePermissionTo([
            Permissions::PROJECT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::DEPARTMENT_READ, Permissions::USER_READ, Permissions::DASHBOARD_VIEW,
            Permissions::FINANCE_BUDGET_READ
        ]);

        $logisticsRole = Role::firstOrCreate(['name' => 'Logistics'], ['description' => 'Logistics and delivery coordination']);
        $logisticsRole->givePermissionTo([
            Permissions::PROJECT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::DEPARTMENT_READ, Permissions::USER_READ, Permissions::DASHBOARD_VIEW
        ]);

        $storesRole = Role::firstOrCreate(['name' => 'Stores'], ['description' => 'Inventory and stores management']);
        $storesRole->givePermissionTo([
            Permissions::PROJECT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::DEPARTMENT_READ, Permissions::USER_READ, Permissions::DASHBOARD_VIEW
        ]);

        $procurementRole = Role::firstOrCreate(['name' => 'Procurement'], ['description' => 'Procurement and sourcing operations']);
        $procurementRole->givePermissionTo([
            Permissions::PROJECT_READ, Permissions::TASK_READ, Permissions::TASK_UPDATE,
            Permissions::DEPARTMENT_READ, Permissions::USER_READ, Permissions::DASHBOARD_VIEW
        ]);
    }
}
