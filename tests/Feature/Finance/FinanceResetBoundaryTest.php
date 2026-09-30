<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Support\FinanceResetBoundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DATA-1: the Finance reset plan must fail closed. Runs against the fully migrated
 * schema (master + Phase 2B), so every foreign key the plan must respect is present.
 */
class FinanceResetBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_plan_is_safe_against_the_migrated_schema(): void
    {
        $this->assertSame([], FinanceResetBoundary::violations());
    }

    public function test_every_preserved_primary_and_dependency_table_is_protected(): void
    {
        foreach (['project_enquiries', 'projects', 'enquiry_tasks', 'task_budget_data', 'budget_additions',
                  'budget_versions', 'quote_approvals', 'task_quote_data', 'employees', 'employee_salary_histories',
                  'clients', 'departments', 'users', 'technical_labours', 'governance_audit_logs', 'hr_audit_logs'] as $table) {
            $this->assertContains($table, FinanceResetBoundary::PROTECTED, "{$table} must be protected");
            $this->assertNotContains($table, FinanceResetBoundary::RESET_ORDER, "{$table} must never be reset");
        }
    }

    public function test_adding_a_protected_table_to_the_reset_fails_closed(): void
    {
        foreach (['project_enquiries', 'employees', 'clients', 'departments', 'task_budget_data', 'technical_labours'] as $table) {
            $violations = FinanceResetBoundary::violations([...FinanceResetBoundary::RESET_ORDER, $table]);
            $this->assertNotEmpty($violations, "Adding {$table} must be refused");
            $this->assertStringContainsString("Protected table '{$table}'", implode("\n", $violations));
        }
    }

    public function test_resetting_a_parent_of_preserved_data_fails_closed_even_if_unlisted(): void
    {
        // `clients` cascades into project_enquiries: detected from the schema even
        // when the protected list is (wrongly) empty.
        $violations = FinanceResetBoundary::violations([...FinanceResetBoundary::RESET_ORDER, 'clients'], []);
        $this->assertStringContainsString("'project_enquiries.client_id' references reset table 'clients'", implode("\n", $violations));
    }

    public function test_a_parent_before_its_blocking_child_fails_closed(): void
    {
        $order = FinanceResetBoundary::RESET_ORDER;
        $po = array_search('purchase_orders', $order, true);
        $items = array_search('purchase_order_items', $order, true);
        [$order[$po], $order[$items]] = [$order[$items], $order[$po]];

        $this->assertStringContainsString(
            "Order deletes 'purchase_orders' before its blocking child 'purchase_order_items",
            implode("\n", FinanceResetBoundary::violations($order)),
        );
    }

    public function test_the_plan_command_is_read_only_and_reports_safe(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('migrations')->count();
        $this->artisan('finance:reset-plan')->assertSuccessful();
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('migrations')->count());
    }
}
