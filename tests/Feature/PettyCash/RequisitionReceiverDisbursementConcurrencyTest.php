<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\RequisitionPaymentAllocation;
use App\Modules\Finance\PettyCash\Services\RequisitionDisbursementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReceiverRequisitionFixture;
use Tests\TestCase;

/**
 * Report 75R-A concurrency — real races, not calls made one after another.
 *
 * Each scenario commits its fixture, forks one process per Finance user, gives
 * every process its own database connection, holds them at a barrier and
 * releases them together to pay the same outstanding balance. The parent then
 * checks the one outcome that may exist: a single settlement, no overpayment.
 *
 * It cannot use RefreshDatabase (its transaction is invisible to the other
 * connections). Instead it records every table's highest id and deletes anything
 * newer afterwards, and it refuses to run against anything but a test database.
 */
class RequisitionReceiverDisbursementConcurrencyTest extends TestCase
{
    use ReceiverRequisitionFixture;

    private array $snapshot = [];
    private array $sequences = [];
    private array $referenceRows = [];
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for true concurrency tests.');
        }
        if (! in_array(DB::connection()->getDatabaseName(), ['db_test', 'db_scratch_test'], true)) {
            $this->fail('This test commits data and must only run against a test database.');
        }
        if (! DB::getSchemaBuilder()->hasTable('petty_cash_surrender_allocations')) {
            $this->markTestSkipped('The test schema is not migrated yet; run the Feature suite first.');
        }

        $this->takeSnapshot();
        $this->dir = sys_get_temp_dir().'/r75ra-race-'.uniqid();
        mkdir($this->dir);

        config(['finance_accounts.seed_reference_chart' => true, 'finance_accounts.map' => []]);
        $this->seedFinanceReferenceData();
        $this->createReceiverRequisition();
    }

    protected function tearDown(): void
    {
        if ($this->snapshot !== []) {
            $this->restoreSnapshot();
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_two_finance_users_paying_the_last_10000_at_once_settle_it_once(): void
    {
        $transport = $this->lines()['Transport'];
        $this->payReceiver([$transport], '20000');
        $cashiers = [$this->finance, $this->secondCashier()];

        $results = $this->race(2, fn (int $i) => app(RequisitionDisbursementService::class)->pay(
            $this->requisition->id, User::findOrFail($cashiers[$i]->id), $this->instructions([$transport], '10000'),
        ));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')), implode(' | ', $results));
        $refusal = collect($results)->first(fn ($r) => $r !== 'ok');
        $this->assertStringContainsString('ValidationException', $refusal);
        $this->assertStringContainsString('still outstanding', $refusal);

        $this->assertSame('30000.00', $this->receiver('Timothy')['paid']);
        $this->assertSame('0.00', $this->receiver('Timothy')['outstanding']);
        $this->assertSame(2, Payment::where('requisition_id', $this->requisition->id)->where('status', 'active')->count());
        $this->assertSame('30000.00', number_format((float) RequisitionPaymentAllocation::where('requisition_item_id', $transport)->sum('allocated_amount'), 2, '.', ''));
        $this->assertSame(2, (int) $this->requisition->fresh()->disbursement_sequence);
    }

    public function test_five_simultaneous_part_payments_never_exceed_the_allocation_and_number_uniquely(): void
    {
        $transport = $this->lines()['Transport'];

        // Five requests of 10,000 against 30,000: exactly three can be honoured.
        $results = $this->race(5, fn (int $i) => app(RequisitionDisbursementService::class)->pay(
            $this->requisition->id, User::findOrFail($this->finance->id), $this->instructions([$transport], '10000'),
        ));

        $this->assertSame(3, count(array_filter($results, fn ($r) => $r === 'ok')), implode(' | ', $results));
        $this->assertSame('30000.00', $this->receiver('Timothy')['paid']);

        $references = Payment::where('requisition_id', $this->requisition->id)->orderBy('id')->pluck('requisition_child_reference')->all();
        $number = $this->requisition->requisition_number;
        $this->assertSame(["$number-D01", "$number-D02", "$number-D03"], $references);
        $this->assertSame(3, Payment::where('requisition_id', $this->requisition->id)->distinct()->count('payment_no'));
    }

    public function test_the_same_request_sent_twice_at_once_creates_one_payment_and_one_journal(): void
    {
        $instructions = $this->instructions([$this->lines()['Materials']], '25000');

        $results = $this->race(2, fn (int $i) => app(RequisitionDisbursementService::class)->pay(
            $this->requisition->id, User::findOrFail($this->finance->id), $instructions,
        ));

        $this->assertSame(['ok', 'ok'], $results, 'A replay is answered with the Payment it already made.');
        $payments = Payment::where('requisition_id', $this->requisition->id)->get();
        $this->assertCount(1, $payments);
        $this->assertSame(1, JournalEntry::where('source_type', Payment::class)->where('source_id', $payments[0]->id)->count());
        $this->assertSame('25000.00', $this->controls()['disbursed']);
    }

    /** Report 75R-B: two people releasing the same unpaid balance at once. */
    public function test_simultaneous_releases_cannot_give_up_more_than_is_unpaid(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED, 'web');
        $releasers = [];
        foreach (['First Controller', 'Second Controller', 'Third Controller'] as $name) {
            $user = User::factory()->create(['is_active' => true, 'name' => $name]);
            $user->givePermissionTo(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED);
            $releasers[] = $user;
        }
        $this->payReceiver([$this->lines()['Materials']], '15000');
        $key = $this->receiver('Winnie')['key'];

        // Winnie has 10,000 approved and unpaid; three people each try to release 6,000.
        $results = $this->race(3, fn (int $i) => app(\App\Modules\Finance\PettyCash\Services\RequisitionClosureService::class)->release(
            $this->requisition->id, User::findOrFail($releasers[$i]->id),
            ['receiver_key' => $key, 'amount' => '6000', 'reason' => 'Supplier delivered less than ordered', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()],
        ));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')), implode(' | ', $results));
        $this->assertSame('6000.00', number_format((float) \App\Modules\Finance\PettyCash\Models\RequisitionBalanceRelease::where('requisition_id', $this->requisition->id)->sum('amount'), 2, '.', ''));
        $winnie = $this->receiver('Winnie');
        $this->assertSame(['6000.00', '4000.00'], [$winnie['released'], $winnie['to_disburse']]);
    }

    /** Report 75R-B: the same surrender reconciled by two people at once posts once. */
    public function test_simultaneous_reconciliation_posts_one_clearing_entry(): void
    {
        $second = $this->secondCashier();
        $this->payReceiver([$this->lines()['Materials']], '25000');
        $accountability = app(\App\Modules\Finance\PettyCash\Services\RequisitionAccountabilityService::class);
        $accountability->confirmReceipt($this->requisition->id, $this->creator, ['receiver_key' => $this->receiver('Winnie')['key'], 'evidence_reference' => 'MPESA-1']);
        $surrender = $accountability->submit($this->requisition->id, $this->creator, [
            'receiver_key' => $this->receiver('Winnie')['key'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['requisition_item_id' => $this->lines()['Materials'], 'expense_code_id' => $this->expenseCodeId, 'amount' => '24000', 'receipt_type' => 'none', 'description' => 'Materials']],
            'returns' => [['requisition_item_id' => $this->lines()['Materials'], 'amount' => '1000']],
        ]);
        $cashiers = [$this->finance, $second];

        $results = $this->race(2, fn (int $i) => app(\App\Modules\Finance\PettyCash\Services\RequisitionAccountabilityService::class)
            ->reconcile($surrender->id, User::findOrFail($cashiers[$i]->id)));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')), implode(' | ', $results));
        $this->assertSame(1, JournalEntry::where('source_type', \App\Modules\Finance\PettyCash\Models\PettyCashSurrender::class)->where('source_id', $surrender->id)->count());
        $this->assertSame(1, \App\Modules\Finance\Models\CashMovement::where('transaction_type', 'requisition_return')->count());
        $this->assertSame(['24000.00', '1000.00', '0.00'], [$this->receiver('Winnie')['accepted'], $this->receiver('Winnie')['returned'], $this->receiver('Winnie')['to_account']]);
    }

    private function secondCashier(): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => 'Second Cashier']);
        $user->givePermissionTo(Permissions::FINANCE_PETTY_CASH_CREATE);

        return $user;
    }

    /** @return array<int, string> 'ok' or the exception each racer met */
    private function race(int $racers, callable $work): array
    {
        $barrier = $this->dir.'/go';
        DB::disconnect();
        $pids = [];

        for ($i = 0; $i < $racers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                $result = 'ok';
                try {
                    DB::purge();
                    DB::reconnect();
                    while (! file_exists($barrier)) {
                        usleep(200);
                    }
                    $work($i);
                } catch (\Throwable $e) {
                    $detail = $e instanceof \Illuminate\Validation\ValidationException
                        ? json_encode($e->errors()) : $e->getMessage();
                    $result = get_class($e).': '.$detail;
                }
                file_put_contents("{$this->dir}/result-{$i}", $result);
                // Leave without running PHPUnit's shutdown in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        usleep(400000); // let every child connect and reach the barrier
        touch($barrier);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        unlink($barrier);
        DB::reconnect();

        $results = [];
        for ($i = 0; $i < $racers; $i++) {
            $raw = @file_get_contents("{$this->dir}/result-{$i}");
            $this->assertNotFalse($raw, 'A racer died without reporting.');
            $results[] = $raw;
        }

        return $results;
    }

    private function takeSnapshot(): void
    {
        $tables = collect(DB::select(
            "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'",
            [DB::connection()->getDatabaseName()],
        ))->pluck('t');

        foreach ($tables as $table) {
            $this->snapshot[$table] = (int) DB::table($table)->max('id');
        }
        // Counters are updated in place, so they are put back by value.
        $this->sequences = DB::table('document_sequences')->pluck('next_number', 'id')->all();
        // So are the catalogue rows a migration created: the seeders this test
        // runs edit them where they stand (activating codes, linking accounts).
        $this->referenceRows = DB::table('expense_codes')->get()->map(fn ($row) => (array) $row)->keyBy('id')->all();
    }

    private function restoreSnapshot(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->snapshot as $table => $maxId) {
                DB::table($table)->where('id', '>', $maxId)->delete();
            }
            foreach ($this->sequences as $id => $next) {
                DB::table('document_sequences')->where('id', $id)->update(['next_number' => $next]);
            }
            foreach ($this->referenceRows as $id => $row) {
                DB::table('expense_codes')->where('id', $id)->update($row);
            }
            DB::table('model_has_permissions')->where('model_id', '>', $this->snapshot['users'] ?? PHP_INT_MAX)->delete();
            DB::table('role_has_permissions')->where('permission_id', '>', $this->snapshot['permissions'] ?? PHP_INT_MAX)->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
