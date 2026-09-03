<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which account a cost is credited to — the leg that says what paid for it.
 *
 * Every journal in the system used to credit the bank, because the fallback read
 * "the first postable account whose category is asset" and in a seeded chart
 * that is Bank – Main Account. For a stores issue no cash moves at all: it moved
 * when the material was bought. So the bank was relieved twice for the same
 * material, and Raw-material Inventory never moved.
 *
 * These assert the accounting, not the plumbing.
 */
class SettlementAccountTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $posting;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);

        $this->posting = $this->app->make(JournalPostingService::class);
        $this->user = User::factory()->create(['is_active' => true]);
    }

    private function costLine(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . uniqid(),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => '1000.00',
            'tax_amount' => '0.00',
            'net_amount' => '1000.00',
            'base_net_amount' => '1000.00',
            'fx_rate' => '1.00',
            'submitted_by_user_id' => $this->user->id,
        ], $overrides));
    }

    private function creditAccountOf(CostLine $line): ChartOfAccount
    {
        $entry = $this->posting->postCostLine($line);
        $this->assertNotNull($entry);

        $credit = $entry->lines()->where('entry_type', 'credit')->firstOrFail();

        return ChartOfAccount::findOrFail($credit->account_id);
    }

    public function test_a_stores_issue_relieves_inventory_not_the_bank(): void
    {
        // The defect in one assertion. Issuing material from your own store does
        // not move money out of the bank — the cash left when it was bought.
        $line = $this->costLine([
            'source_type' => InventoryLog::class,
            'source_id' => 1,
            'source_ref' => 'stock-issue',
        ]);

        $account = $this->creditAccountOf($line);

        $this->assertSame('1200', $account->code, 'A stores issue must credit Raw-material Inventory.');
        $this->assertNotSame('1010', $account->code, 'A stores issue must never credit the bank.');
    }

    public function test_a_stores_return_puts_the_value_back_into_inventory(): void
    {
        // A negative net swaps the legs, so the same account serves both
        // directions: the return debits inventory and credits WIP.
        $line = $this->costLine([
            'source_type' => InventoryLog::class,
            'source_id' => 2,
            'source_ref' => 'stock-return',
            'amount' => '-250.00',
            'net_amount' => '-250.00',
            'base_net_amount' => '-250.00',
        ]);

        $entry = $this->posting->postCostLine($line);
        $debit = $entry->lines()->where('entry_type', 'debit')->firstOrFail();

        $this->assertSame(
            '1200',
            ChartOfAccount::findOrFail($debit->account_id)->code,
            'A return must debit Raw-material Inventory — the material is back on the shelf.',
        );
    }

    public function test_goods_received_but_not_invoiced_becomes_a_liability(): void
    {
        // Accepting delivery creates an obligation to the supplier. It is not a
        // payment, and it is not yet an invoice.
        $line = $this->costLine([
            'nature' => CostLine::NATURE_ACCRUED,
            'source_ref' => 'accrual',
        ]);

        $this->assertSame('2150', $this->creditAccountOf($line)->code);
    }

    public function test_goods_received_land_in_stock_not_in_project_wip(): void
    {
        // The double-count. Material bought for a job still goes through the
        // store, so receiving it is a stock movement; the project is charged
        // when it is issued. Debiting WIP here and again at issue charged the
        // job twice for one delivery.
        $line = $this->costLine([
            'nature' => CostLine::NATURE_ACCRUED,
            'source_ref' => 'accrual',
        ]);

        $entry = $this->posting->postCostLine($line);
        $debit = $entry->lines()->where('entry_type', 'debit')->firstOrFail();
        $account = ChartOfAccount::findOrFail($debit->account_id);

        $this->assertSame('1200', $account->code, 'A goods receipt must debit Raw-material Inventory.');
        $this->assertNotSame('1211', $account->code, 'A goods receipt must not touch Project WIP.');
    }

    public function test_the_full_material_cycle_touches_project_wip_exactly_once(): void
    {
        // Receive, then issue. Across both journals WIP must be debited once —
        // that is the whole point of routing purchases through the store.
        $receipt = $this->costLine([
            'nature' => CostLine::NATURE_ACCRUED,
            'source_ref' => 'accrual',
        ]);
        $issue = $this->costLine([
            'source_type' => InventoryLog::class,
            'source_id' => 99,
            'source_ref' => 'stock-issue',
        ]);

        $wipId = ChartOfAccount::where('code', '1211')->value('id');
        $inventoryId = ChartOfAccount::where('code', '1200')->value('id');

        $receiptEntry = $this->posting->postCostLine($receipt);
        $issueEntry = $this->posting->postCostLine($issue);

        $wipDebits = \App\Modules\Finance\Models\JournalLine::whereIn(
            'journal_entry_id', [$receiptEntry->id, $issueEntry->id]
        )->where('account_id', $wipId)->where('entry_type', 'debit')->count();

        $this->assertSame(1, $wipDebits, 'Project WIP must be debited once across receipt and issue.');

        // And inventory goes up on receipt, down on issue.
        $this->assertSame(1, \App\Modules\Finance\Models\JournalLine::where('journal_entry_id', $receiptEntry->id)
            ->where('account_id', $inventoryId)->where('entry_type', 'debit')->count());
        $this->assertSame(1, \App\Modules\Finance\Models\JournalLine::where('journal_entry_id', $issueEntry->id)
            ->where('account_id', $inventoryId)->where('entry_type', 'credit')->count());
    }

    public function test_issuing_material_retires_the_receipt_accrual(): void
    {
        // The relay. A job's spend is committed + accrued + actual and each
        // stage retires the one before; nothing retired the accrual, so between
        // receiving material and issuing it the job carried both figures.
        // Producer-posted lines land verified, which is what releaseAccrual acts on.
        $accrual = $this->costLine([
            'nature' => CostLine::NATURE_ACCRUED,
            'status' => CostLine::STATUS_VERIFIED,
            'source_ref' => 'accrual',
            'details' => ['library_material_id' => 77],
        ]);
        $this->posting->postCostLine($accrual);

        $this->assertSame(CostLine::STATUS_VERIFIED, $accrual->fresh()->status);

        // Now issue that material to the same job.
        app(\App\Modules\Finance\CostCollector\Services\CostCollectorService::class)
            ->releaseAccrual($accrual, 'Retired by Stores issue.');

        $accrual = $accrual->fresh();

        $this->assertSame(
            CostLine::STATUS_REVERSED,
            $accrual->status,
            'The accrual must stop counting as project spend once the material is issued.',
        );

        // The stock journal stays: the goods exist and the supplier is still
        // owed. Reversing it would erase a liability nobody has settled.
        $this->assertNotNull($accrual->journal_entry_id);
        $this->assertSame(
            'posted',
            \App\Modules\Finance\Models\JournalEntry::findOrFail($accrual->journal_entry_id)->status,
        );
    }

    public function test_petty_cash_spend_credits_the_float_it_came_out_of(): void
    {
        $source = \App\Modules\Finance\Models\PaymentSource::whereNotNull('gl_account_id')->firstOrFail();

        $line = $this->costLine([
            'source_ref' => 'petty-cash',
            'details' => ['payment_source_id' => $source->id],
        ]);

        $this->assertSame(
            (int) $source->gl_account_id,
            $this->creditAccountOf($line)->id,
            'Spend from a named float must credit that float, not a generic asset.',
        );
    }

    public function test_an_untraceable_cost_is_owed_rather_than_paid(): void
    {
        // The conservative fallback. If we cannot say what settled a cost, the
        // safe claim is that we still owe it — never that we paid it from the
        // bank, which would understate cash.
        // Manually captured costs carry an empty source_ref, which is what the
        // seven hand-entered lines in the live data look like.
        $line = $this->costLine(['source_ref' => '']);

        $account = $this->creditAccountOf($line);

        $this->assertSame('2100', $account->code);
        $this->assertSame('liability', $account->category);
    }
}
