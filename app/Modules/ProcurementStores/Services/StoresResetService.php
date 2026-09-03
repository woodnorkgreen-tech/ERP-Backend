<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\GovernanceAuditLog;
use App\Modules\ProcurementStores\Models\StockCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-time wipe of the Stores inventory ledger so a fresh opening inventory
 * can establish the real baseline. This exists for the window before a site
 * goes live; it disarms itself permanently once an opening inventory has been
 * approved, because from that point the ledger is the audited book of record.
 *
 * The catalogue (library_materials) and project planning data
 * (element_materials) are never touched — only what Stores has recorded.
 */
class StoresResetService
{
    public const CONFIRMATION_PHRASE = 'RESET STORES';

    /**
     * Deleted in dependency order. Both board_return_batch_items.board_id and
     * inventory_logs.original_issue_log_id are NO ACTION foreign keys, so the
     * children and the self-reference must be cleared before their parents.
     */
    private const WIPE_ORDER = [
        'board_return_batch_items',
        'board_return_batches',
        'board_movements',
        'board_workflow_tasks',
        'boards',
        'board_requests',
        'board_reconciliations',
        'stock_count_items',
        'stock_counts',
        'inventory_movement_allocations',
        'inventory_logs',
        'inventory_serial_items',
        'inventory_lots',
        'stocks',
    ];

    /** Row counts for everything the reset would delete, highest tables first. */
    public function preview(): array
    {
        $counts = [];
        foreach (self::WIPE_ORDER as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        return [
            'tables' => $counts,
            'total_rows' => array_sum($counts),
            'blockers' => $this->blockers(),
            'preserved' => [
                'library_materials' => DB::table('library_materials')->count(),
                'element_materials' => DB::table('element_materials')->count(),
            ],
        ];
    }

    /**
     * Reasons the reset must not run. Empty means it is safe to proceed.
     *
     * @return array<int, string>
     */
    public function blockers(): array
    {
        $blockers = [];

        if (StockCount::where('mode', StockCount::MODE_OPENING)->where('status', 'approved')->exists()) {
            $blockers[] = 'An opening inventory has already been approved. Stores is live and its ledger is the audited record; correct stock with a cycle count instead.';
        }

        // GRN items point at inventory_logs with SET NULL, so a receipt would
        // survive the wipe with no movement behind it. Refuse rather than
        // silently strand procurement's receiving history.
        $grns = DB::table('goods_receipt_notes')->count();
        if ($grns > 0) {
            $blockers[] = "There are {$grns} goods receipt notes. Clear procurement receiving before resetting Stores, or their receipts will point at deleted movements.";
        }

        return $blockers;
    }

    /**
     * @return array<string, int> rows deleted per table
     */
    public function reset(string $reason): array
    {
        $blockers = $this->blockers();
        if ($blockers !== []) {
            throw ValidationException::withMessages(['reset' => $blockers]);
        }

        return DB::transaction(function () use ($reason) {
            $deleted = [];
            DB::table('inventory_logs')->update(['original_issue_log_id' => null]);
            foreach (self::WIPE_ORDER as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            GovernanceAuditLog::create([
                'user_id' => auth()->id(),
                'gate_type' => 'stores_reset',
                'action_status' => 'completed',
                'message' => 'Stores inventory reset before opening inventory: '.$reason,
                'context' => ['deleted' => $deleted, 'total_rows' => array_sum($deleted)],
                'ip_address' => request()->ip(),
            ]);

            return $deleted;
        });
    }
}
