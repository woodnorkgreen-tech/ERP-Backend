<?php

namespace Tests\Feature\Finance;

use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillLineReceiptAllocation;
use App\Modules\ProcurementStores\Services\BillMatchingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Report 76A-3 §9 E — two invoices racing for the same receipt.
 *
 * Real processes, real commits: RefreshDatabase's transaction would be
 * invisible to the other racer, so this test commits its fixtures and deletes
 * them afterwards by id. It therefore runs only against a database named as a
 * test database, exactly as the other forked race tests do.
 */
class Report76A3BillingConcurrencyTest extends TestCase
{
    use \Tests\Feature\Finance\Concerns\ProcurementAccountingFixtures {
        setUp as fixturesSetUp;
    }

    private array $snapshot = [];
    private array $sequences = [];
    private array $referenceRows = [];
    private string $dir;

    protected function setUp(): void
    {
        TestCase::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for true concurrency tests.');
        }
        if (! in_array(DB::connection()->getDatabaseName(), ['db_test', 'db_scratch_test'], true)) {
            $this->fail('This test commits data and must only run against a test database (db_test or db_scratch_test).');
        }
        if (! DB::getSchemaBuilder()->hasTable('bill_line_receipt_allocations')) {
            $this->markTestSkipped('The test schema is not migrated yet; run the Feature suite first.');
        }

        $this->takeSnapshot();
        $this->dir = sys_get_temp_dir().'/r76a3-race-'.uniqid();
        mkdir($this->dir);
        $this->prepareProcurementFixtures();
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
        \App\Modules\Finance\Governance\GovernanceRuntime::flush();

        parent::tearDown();
    }

    public function test_two_invoices_claiming_the_same_receipt_at_once_bill_it_once(): void
    {
        $item = $this->order(100, 1000, $this->material('MDF 18mm')->id);
        $receipt = $this->receive($item, 100);
        $orderId = $item->purchase_order_id;
        $supplierId = $this->supplier->id;
        $userId = $this->user->id;

        $results = $this->race(4, function (int $i) use ($item, $receipt, $orderId, $supplierId, $userId) {
            $bill = Bill::create([
                'bill_number' => 'BILL-RACE-'.uniqid().'-'.$i, 'purchase_order_id' => $orderId, 'supplier_id' => $supplierId,
                'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'amount' => 100000,
                'status' => 'pending', 'user_id' => $userId, 'supplier_invoice_number' => 'RACE-'.$i,
            ]);
            app(BillMatchingService::class)->syncStated($bill, [[
                'purchase_order_item_id' => $item->id, 'quantity' => 100, 'unit_price_net' => 1000,
                'allocations' => [['goods_receipt_note_item_id' => $receipt->id, 'quantity' => 100]],
            ]]);
        });

        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'ok')), implode(' | ', $results));
        $this->assertSame(1, BillLineReceiptAllocation::where('goods_receipt_note_item_id', $receipt->id)->count());
        $this->assertSame('100.000000', (string) BillLineReceiptAllocation::where('goods_receipt_note_item_id', $receipt->id)->sum('quantity'));
        $this->assertSame('0.000000', app(BillMatchingService::class)->outstanding($receipt->fresh())['quantity']);
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
        // Counters and catalogue rows are edited in place, so they are put back by value.
        $this->sequences = DB::table('document_sequences')->pluck('next_number', 'id')->all();
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
