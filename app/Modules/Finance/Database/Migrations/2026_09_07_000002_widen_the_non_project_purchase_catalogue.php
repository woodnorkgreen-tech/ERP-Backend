<?php

use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives an office or stores requisition something to say.
 *
 * A requisition raised without a project could pick from seven expense types in
 * four families: office transport, airtime, staff welfare — and "Inventory
 * purchase", "Prepayments", "Leasehold improvements", which name the account
 * the money lands in rather than the thing being bought. Every other purchase
 * the company makes that is not against a job — stationery, drill bits, PPE,
 * detergent, a machine service, the electricity bill — had no classification at
 * all, so it was either coded to something plausible-but-wrong or typed as a
 * free-text description with no expense code behind it.
 *
 * That was never a filter bug. The September 2026 fix to `applyJobContext`
 * corrected which codes the no-project list *asks* for; this supplies the codes
 * it was asking about. The catalogue holds 42 material types plus hire,
 * subcontracting and logistics, and every one of them requires a job number by
 * design, which is right — they just leave nothing behind for the office.
 *
 * WHY THE SEEDER RUNS RATHER THAN A COPY OF ITS DATA
 *
 * ExpenseCodeSeeder is the catalogue's source of truth and is idempotent —
 * updateOrCreate keyed on `code`, no deletes. Restating nine rows and four
 * renames here would create a second copy to keep in step, and the last time
 * this catalogue had two vocabularies for the same thing is what the requisition
 * alignment migration was written to undo. So the seeder is called, and it is
 * called after 7150 exists because it resolves each code's debit account by
 * looking the four-digit prefix up among postable accounts: run it first and
 * the two office-supplies codes would resolve to null and seed as inactive.
 */
return new class extends Migration
{
    /** Added by this change; listed so down() knows what it introduced. */
    private const INTRODUCED = [
        'OE-OFF-001', 'OE-OFF-002',
        'OE-WSC-001', 'OE-MNT-001', 'OE-PPE-001',
        'OE-CLN-001', 'OE-WST-001', 'OE-UTL-001', 'OE-UTL-002',
    ];

    /** new family name => the ledger-flavoured name it replaces. */
    private const RENAMED = [
        'Stock replenishment' => 'Inventory purchase',
        'Rent and insurance paid in advance' => 'Prepayments',
        'Premises improvements' => 'Leasehold improvements',
        'Staff and administration' => 'Administration',
    ];

    /** Tables that point at an expense code, and the column that does it. */
    private const CODE_REFERENCES = [
        'cost_lines' => 'expense_code_id',
        'requisition_items' => 'expense_code_id',
        'petty_cash_disbursements' => 'expense_code_id',
        'petty_cash_requisition_types' => 'default_expense_code_id',
        'posting_rules' => 'expense_code_id',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $this->addOfficeSuppliesAccount();

            // Adds the nine codes and applies the four family renames, since
            // both live in the seeder classes.
            (new ExpenseCodeSeeder)->run();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::RENAMED as $new => $old) {
                DB::table('expense_codes')->where('expense_family', $new)
                    ->update(['expense_family' => $old, 'updated_at' => now()]);
            }

            // A code somebody has already requisitioned against is retired, not
            // deleted — the same rule the rest of this module applies. The FKs
            // are nullOnDelete, so deleting a used code would silently strip the
            // classification off history rather than fail loudly.
            foreach (self::INTRODUCED as $code) {
                $id = DB::table('expense_codes')->where('code', $code)->value('id');

                if ($id === null) {
                    continue;
                }

                if ($this->isReferenced($id)) {
                    DB::table('expense_codes')->where('id', $id)
                        ->update(['is_active' => false, 'updated_at' => now()]);

                    continue;
                }

                DB::table('expense_codes')->where('id', $id)->delete();
            }

            $account = DB::table('chart_of_accounts')->where('code', '7150')->first();

            if ($account === null) {
                return;
            }

            // Only if nothing was left pointing at it — a retired code above
            // still names it as its debit account.
            $stillUsed = DB::table('expense_codes')->where('default_debit_account_id', $account->id)->exists()
                || (Schema::hasTable('journal_lines') && DB::table('journal_lines')->where('account_id', $account->id)->exists());

            if (! $stillUsed) {
                DB::table('chart_of_accounts')->where('id', $account->id)->delete();
            }
        });
    }

    /**
     * 7150 Office Supplies & Stationery.
     *
     * The one account this change adds rather than reuses. OperationalExpenseCodes
     * had recorded the gap in a comment — office supplies were left out because
     * "the chart has no account for them, and forcing them into Office Rent &
     * Electricity would be worse than leaving the gap visible" — and the
     * requisition-type alignment retired the folk category "Office Supplies" for
     * the same reason. Stationery is the most ordinary purchase in the building;
     * it gets its own leaf under 7000 Operating Expenses.
     */
    private function addOfficeSuppliesAccount(): void
    {
        if (DB::table('chart_of_accounts')->where('code', '7150')->exists()) {
            return;
        }

        DB::table('chart_of_accounts')->insert([
            'code' => '7150',
            'name' => 'Office Supplies & Stationery',
            'category' => 'expense',
            'account_type' => 'opex',
            'normal_balance' => 'debit',
            'parent_id' => DB::table('chart_of_accounts')->where('code', '7000')->value('id'),
            'is_postable' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Checked by column so a table missing from this environment is not fatal. */
    private function isReferenced(int $expenseCodeId): bool
    {
        foreach (self::CODE_REFERENCES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $expenseCodeId)->exists()) {
                return true;
            }
        }

        return false;
    }
};
