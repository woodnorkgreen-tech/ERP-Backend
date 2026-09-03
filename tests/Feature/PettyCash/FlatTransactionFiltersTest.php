<?php

namespace Tests\Feature\PettyCash;

use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cashbook listing behind `GET /transactions`.
 *
 * PettyCashController collects `status`, `classification`, `payment_method`,
 * `creator_id` and `show_archived`, and getFlatTransactions applied none of
 * them: the panel narrowed nothing while reporting itself as active, and
 * paginated over the whole ledger regardless. It also read `balance_snapshot`
 * only to derive `previous_balance` from it, so the one figure that makes the
 * list a cashbook never reached the client.
 */
class FlatTransactionFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private int $topUpId;
    private PettyCashRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PettyCashRepository();
        $this->actor = User::factory()->create(['is_active' => true]);

        $topUp = PettyCashTopUp::create([
            'amount' => 100000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->actor->id,
        ]);
        $this->topUpId = $topUp->id;

        (new LedgerService())->post(LedgerEntry::creditForTopUp($topUp));
    }

    private function postDisbursement(array $overrides = []): PettyCashDisbursement
    {
        $disbursement = PettyCashDisbursement::create(array_merge([
            'top_up_id' => $this->topUpId,
            'amount' => 1000.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'status' => 'active',
            'is_archived' => false,
            'date_disbursed' => now()->toDateString(),
            'created_by' => $this->actor->id,
        ], $overrides));

        (new LedgerService())->post(LedgerEntry::debitForDisbursement($disbursement));

        return $disbursement;
    }

    /** @return array<int, object> */
    private function rows(array $filters = []): array
    {
        return $this->repository->getFlatTransactions($filters, 50)->items();
    }

    public function test_every_entry_carries_the_balance_it_left_behind(): void
    {
        $this->postDisbursement(['amount' => 1000.00]);
        $this->postDisbursement(['amount' => 2500.00]);

        // Newest first, so the balances walk back up as you read down.
        $balances = array_map(fn ($row) => $row->balance_after, $this->rows());

        $this->assertSame([96500.0, 99000.0, 100000.0], $balances);
    }

    public function test_the_classification_filter_narrows_the_ledger(): void
    {
        $this->postDisbursement(['classification' => 'operations']);
        $this->postDisbursement(['classification' => 'admin']);

        $rows = $this->rows(['classification' => 'admin']);

        $this->assertCount(1, $rows);
        $this->assertSame('admin', $rows[0]->classification);
    }

    public function test_the_payment_method_filter_narrows_the_ledger(): void
    {
        $this->postDisbursement(['payment_method' => 'cash']);
        $this->postDisbursement(['payment_method' => 'ncba', 'transaction_code' => 'NCBA-77812']);

        $rows = $this->rows(['payment_method' => 'ncba']);

        $this->assertCount(1, $rows);
        $this->assertSame('ncba', $rows[0]->payment_method);
    }

    public function test_a_top_up_counts_as_active_though_it_records_no_status(): void
    {
        $this->postDisbursement(['status' => 'active']);
        $this->postDisbursement(['status' => 'voided']);

        $active = $this->rows(['status' => 'active']);
        $voided = $this->rows(['status' => 'voided']);

        // The credit plus the one active debit — a top-up has no status key and
        // must not fall out of the active view because of it.
        $this->assertCount(2, $active);
        $this->assertCount(1, $voided);
        $this->assertSame('top_up', $active[1]->type);
    }

    public function test_archiving_a_source_record_hides_its_ledger_entry(): void
    {
        $kept = $this->postDisbursement(['receiver' => 'Bolt']);
        $archived = $this->postDisbursement(['receiver' => 'Uber']);
        $archived->update(['is_archived' => true]);

        $current = $this->rows();
        $this->assertCount(2, $current);
        $this->assertSame([false, false], array_map(fn ($row) => $row->is_archived, $current));
        $this->assertSame('Bolt', $current[0]->receiver);

        $archivedRows = $this->rows(['show_archived' => true]);
        $this->assertCount(1, $archivedRows);
        $this->assertSame('Uber', $archivedRows[0]->receiver);
        $this->assertTrue($archivedRows[0]->is_archived);

        $this->assertSame($kept->id, (int) substr($current[0]->reference_number, 4, 6));
    }

    public function test_the_archived_view_is_empty_rather_than_unfiltered_when_nothing_is_archived(): void
    {
        $this->postDisbursement();

        // A naive "match nothing" implementation degrades into "match everything",
        // which is how an empty archive ends up showing the live ledger.
        $this->assertCount(0, $this->rows(['show_archived' => true]));
    }

    public function test_filters_narrow_the_paginated_total_not_just_the_page(): void
    {
        $this->postDisbursement(['classification' => 'admin']);
        $this->postDisbursement(['classification' => 'operations']);
        $this->postDisbursement(['classification' => 'operations']);

        $paginator = $this->repository->getFlatTransactions(['classification' => 'operations'], 1);

        $this->assertSame(2, $paginator->total());
        $this->assertCount(1, $paginator->items());
    }
}
