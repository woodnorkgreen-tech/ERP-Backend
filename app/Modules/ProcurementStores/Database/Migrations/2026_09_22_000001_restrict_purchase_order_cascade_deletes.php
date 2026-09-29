<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Critical Risk C3 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * purchase_order_items, goods_receipt_notes, and bills (created as `invoices`
 * before the 2026-01-22 rename — which is why, on any environment where the
 * database kept the original constraint name, its foreign key is still named
 * after that table) all cascade-delete on purchase_order_id. Deleting an
 * approved, paid purchase order therefore wiped its bill(s) and goods receipt
 * note(s) at the database level, orphaning already-posted JournalEntry and
 * BillPayment rows, bypassing every application-level guard.
 *
 * The primary defense is the guard now in PurchaseOrderController::destroy()
 * (see STAB-3/C3 in finance-redesign/current-state and finance-redesign/phase-2).
 * This migration is defense-in-depth for any write path that does not go
 * through that controller — a raw DB::table() delete, a console command, a
 * future admin tool.
 *
 * This only tightens the ON DELETE action for future deletes; it does not
 * touch any existing row, so it cannot fail against current data the way a
 * column/type change could. The constraint name is looked up dynamically
 * (information_schema) rather than assumed, since the 2026-01-22 rename may
 * or may not have carried the original `invoices_...` constraint name forward
 * depending on the database engine's rename behaviour at the time it ran.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, column: string, references: string}> */
    private array $targets = [
        ['table' => 'purchase_order_items', 'column' => 'purchase_order_id', 'references' => 'purchase_orders'],
        ['table' => 'goods_receipt_notes', 'column' => 'purchase_order_id', 'references' => 'purchase_orders'],
        ['table' => 'bills', 'column' => 'purchase_order_id', 'references' => 'purchase_orders'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $target) {
            $this->replace($target['table'], $target['column'], $target['references'], 'restrict');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->targets) as $target) {
            $this->replace($target['table'], $target['column'], $target['references'], 'cascade');
        }
    }

    private function currentConstraintName(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$table, $column]
        );

        return $row->CONSTRAINT_NAME ?? null;
    }

    private function replace(string $table, string $column, string $references, string $onDelete): void
    {
        $constraint = $this->currentConstraintName($table, $column);

        if (! $constraint) {
            // No FK on this column in this environment (e.g. it was dropped
            // or never created here) — nothing to tighten.
            return;
        }

        Schema::table($table, function ($blueprint) use ($constraint, $column, $references, $onDelete) {
            $blueprint->dropForeign($constraint);
            $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete);
        });
    }
};
