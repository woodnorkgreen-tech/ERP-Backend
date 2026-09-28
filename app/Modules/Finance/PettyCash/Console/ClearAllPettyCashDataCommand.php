<?php

namespace App\Modules\Finance\PettyCash\Console;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursementAllocation;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Critical Risk C6 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
 * this replaces the former `DELETE /finance/petty-cash/clear-all` endpoint,
 * which was reachable over HTTP (Super Admin only, but reachable) and had no
 * confirmation, no export-first step, and no environment restriction beyond
 * a controller-level check. Per the confirmed stabilization decision
 * (STAB-5, finance-redesign/phase-2/03_WNG_FINANCE_DECISION_REGISTER.md):
 * no legitimate production use case exists for wiping petty-cash history,
 * so the capability is removed from the API entirely and kept only as a
 * local/testing-only console command, guarded so it cannot run in
 * production even by accident.
 *
 * This is a genuinely destructive, irreversible action. It exists solely
 * for resetting demo/development data.
 */
class ClearAllPettyCashDataCommand extends Command
{
    protected $signature = 'petty-cash:clear-all-non-production {--force : Skip the interactive confirmation}';

    protected $description = 'Permanently wipes all petty cash disbursements, top-ups and ledger history. Local/testing environments only.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: this command is disabled in production. Use voids and reversals to correct petty cash history instead.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This will permanently delete every petty cash disbursement, top-up, allocation and ledger entry in the '
            .app()->environment().' database. This cannot be undone. Continue?'
        )) {
            $this->comment('Cancelled — nothing was deleted.');

            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            $allocationCount = PettyCashDisbursementAllocation::count();
            PettyCashDisbursementAllocation::query()->delete();

            $disbursementsCount = Payment::count();
            Payment::query()->delete();

            $topUpsCount = PettyCashTopUp::count();
            PettyCashTopUp::query()->delete();

            DB::table('petty_cash_ledger_entries')->delete();
            (new LedgerService())->rebuildFromLedger();

            app(PettyCashService::class)->logActivity(
                'cleared',
                null,
                null,
                "All petty cash data cleared via console command. Deleted {$topUpsCount} top-ups, "
                ."{$disbursementsCount} disbursements, and {$allocationCount} allocations.",
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Failed to clear petty cash data: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Cleared {$topUpsCount} top-ups, {$disbursementsCount} disbursements, and {$allocationCount} allocations. Balance rebuilt from the (now empty) ledger.");

        return self::SUCCESS;
    }
}
