<?php

namespace App\Modules\Finance\Support;

use Illuminate\Support\Facades\DB;

/**
 * DATA-1: the controlled Finance reset boundary (Reports 46/47).
 *
 * This class DESCRIBES and VERIFIES the reset; it deletes nothing. There is no
 * delete code anywhere in it, deliberately: the reset is executed only by a
 * separately reviewed, rehearsed release procedure (Report 47 §18).
 *
 * RESET_ORDER is child-first on every blocking (NO ACTION / RESTRICT) foreign key.
 * SET NULL links never block a delete, so they impose no order. violations()
 * re-derives all of this from the live schema, so the plan fails closed if:
 *   - a protected table is ever added to the reset list;
 *   - any table outside the reset set references a reset table (resetting would
 *     null, delete, or be blocked by data that must survive);
 *   - the order would delete a parent before a blocking child.
 */
final class FinanceResetBoundary
{
    /** Delete in this order. Tables absent from a schema are skipped (Phase 2B tables before migration). */
    public const RESET_ORDER = [
        // W7 / W6 analytical records
        'project_labour_actual_returns',
        'project_labour_actuals',
        'cost_line_allocations',
        'cost_line_transfers',
        'payment_allocations',
        'spend_voucher_allocations',
        'spend_voucher_reviews',
        'stores_finance_postings',
        // General ledger and reconciliation
        'journal_lines',
        'journal_entries',
        'finance_statement_matches',
        'finance_statement_transactions',
        'finance_reconciliation_statements',
        'finance_cash_movements',
        'cost_lines',
        // Receivables (Q2)
        'project_invoice_allocations',
        'project_invoice_lines',
        'project_invoices',
        'enquiry_payments',
        'client_receipts',
        // Petty cash and payments (Q1)
        'petty_cash_surrender_reviews',
        'petty_cash_surrender_items',
        'petty_cash_disbursement_allocations',
        'petty_cash_requisition_items',
        'petty_cash_requisitions',
        'petty_cash_offline_rows',
        'petty_cash_offline_batches',
        'direct_disbursement_requests',
        'bill_payments',
        'payments',
        'petty_cash_top_ups',
        'petty_cash_ledger_entries',
        'petty_cash_activity_logs',
        'petty_cash_cash_counts',
        'petty_cash_custody_handovers',
        'petty_cash_balances',        // PettyCashBalance::current() recreates the float at 0.00
        'spend_vouchers',
        // Payroll transactions (Q3) — never Employee Records or HR history
        'salary_advance_recoveries',
        'payslips',
        'payroll_ledgers',
        'salary_advance_requests',
        'payroll_runs',
        // Procurement-to-pay documents
        'bills',
        'goods_receipt_inspections',
        'goods_receipt_note_items',
        'goods_receipt_notes',
        'purchase_order_amendments',
        'purchase_order_corrections',
        'purchase_order_items',
        'purchase_orders',
        // Polymorphic evidence rows of the above (stored files are not deleted by this plan)
        'finance_attachments',
    ];

    /**
     * Reset only if production shows them empty or WNG confirms their rows are
     * development/test (Report 46 §19). Stock already received stays in Stores.
     */
    public const CONFIRM_ON_PRODUCTION = [
        'project_invoices', 'project_invoice_lines', 'project_invoice_allocations', 'client_receipts',
        'bills', 'bill_payments', 'goods_receipt_notes', 'goods_receipt_note_items', 'goods_receipt_inspections',
        'purchase_orders', 'purchase_order_items', 'spend_vouchers', 'spend_voucher_allocations',
        'finance_reconciliation_statements', 'finance_statement_transactions', 'finance_statement_matches',
        'finance_cash_movements', 'finance_attachments',
    ];

    /** Never deleted, updated, or truncated by the Finance reset (DATA-1). */
    public const PROTECTED = [
        // Primary
        'project_enquiries', 'projects', 'employees',
        // Project operational data
        'enquiry_tasks', 'enquiry_task_user', 'task_assignment_history', 'task_budget_data', 'budget_additions',
        'budget_versions', 'budget_approvals', 'task_quote_data', 'quote_approvals', 'quote_versions',
        'task_materials_data', 'task_procurement_data', 'task_production_data', 'project_deliverables',
        'project_elements', 'element_materials', 'teams_tasks', 'teams_members', 'site_surveys',
        'design_requirements', 'design_assets', 'logistics_tasks', 'setup_tasks', 'setdown_tasks',
        'handover_surveys', 'archival_reports',
        // Required dependencies
        'clients', 'departments', 'users',
        // Employee HR history
        'employee_salary_histories', 'employee_documents', 'employee_certifications', 'employee_skills',
        'hr_actions', 'disciplinary_cases', 'performance_reviews', 'profile_update_requests', 'leave_requests',
        'leave_handovers', 'leave_balance_adjustments', 'attendance_records', 'attendance_schedule_assignments',
        'drivers',
        // Historical compatibility
        'technical_labours',
        // Master data (operational, Finance, HR reference)
        'suppliers', 'library_materials', 'material_categories', 'material_versions', 'units_of_measure',
        'chart_of_accounts', 'accounting_periods', 'expense_codes', 'cost_centres', 'cost_causes', 'activities',
        'payee_types', 'payment_sources', 'vat_treatments', 'wht_categories', 'posting_rules', 'finance_settings',
        'petty_cash_requisition_types', 'payment_terms', 'leave_types', 'payroll_tax_bands', 'payroll_variables',
        // Security / system / audit
        'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions',
        'personal_access_tokens', 'sessions', 'migrations', 'governance_audit_logs', 'hr_audit_logs',
        'finance_period_audit_logs', 'action_logs', 'system_events',
        // Cross-module (outside the Finance reset)
        'requisitions', 'requisition_items', 'stocks', 'inventory_logs', 'work_orders', 'boards',
        'ot_entries', 'compensations', 'ledger_entries',
    ];

    /** Counted before and after the reset; every count must be identical. */
    public const PRESERVATION_COUNTS = [
        'project_enquiries', 'projects', 'enquiry_tasks', 'task_budget_data', 'budget_additions', 'budget_versions',
        'quote_approvals', 'employees', 'employee_salary_histories', 'clients', 'departments', 'users',
        'teams_members', 'technical_labours', 'governance_audit_logs', 'hr_audit_logs',
    ];

    /**
     * Every reason the plan is unsafe against the connected schema; empty means safe.
     *
     * @param  array<int, string>|null  $order  override, for tests
     * @param  array<int, string>|null  $protected  override, for tests
     * @return array<int, string>
     */
    public static function violations(?array $order = null, ?array $protected = null): array
    {
        $order ??= self::RESET_ORDER;
        $protected ??= self::PROTECTED;
        $violations = [];

        foreach (array_intersect($order, $protected) as $table) {
            $violations[] = "Protected table '{$table}' is in the reset order.";
        }
        foreach (array_diff_assoc($order, array_unique($order)) as $table) {
            $violations[] = "Table '{$table}' appears more than once in the reset order.";
        }

        $position = array_flip($order);
        foreach (self::foreignKeys() as $fk) {
            $childReset = isset($position[$fk->child]);
            $parentReset = isset($position[$fk->parent]);

            if ($parentReset && ! $childReset && $fk->child !== $fk->parent) {
                $violations[] = "'{$fk->child}.{$fk->col}' references reset table '{$fk->parent}' ({$fk->rule}): resetting would touch data outside the reset set.";
            }
            if ($childReset && $parentReset && $fk->child !== $fk->parent
                && in_array($fk->rule, ['NO ACTION', 'RESTRICT'], true)
                && $position[$fk->child] > $position[$fk->parent]) {
                $violations[] = "Order deletes '{$fk->parent}' before its blocking child '{$fk->child}.{$fk->col}'.";
            }
        }

        return $violations;
    }

    /** Reset tables that exist in the connected schema, in order. */
    public static function presentResetTables(): array
    {
        $existing = collect(DB::select(
            'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        ))->pluck('t')->all();

        return array_values(array_intersect(self::RESET_ORDER, $existing));
    }

    /** @return array<int, object{child:string, col:string, parent:string, rule:string}> */
    private static function foreignKeys(): array
    {
        return DB::select(
            'SELECT k.TABLE_NAME AS child, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS parent, r.DELETE_RULE AS rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );
    }
}
