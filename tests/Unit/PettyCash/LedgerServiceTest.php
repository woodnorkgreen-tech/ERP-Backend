<?php

namespace Tests\Unit\PettyCash;

use App\Modules\Finance\PettyCash\Services\LedgerService;
use App\Modules\Finance\PettyCash\Services\LedgerEntry;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class LedgerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('petty_cash_balances');
        Schema::dropIfExists('petty_cash_ledger_entries');

        Schema::create('petty_cash_balances', function ($table) {
            $table->id();
            $table->decimal('current_balance', 10, 2)->default(0.00);
            $table->unsignedBigInteger('last_transaction_id')->nullable();
            $table->string('last_transaction_type')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('petty_cash_ledger_entries', function ($table) {
            $table->id();
            $table->string('reference_number');
            $table->string('type');
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->decimal('balance_snapshot', 10, 2)->default(0.00);
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        PettyCashBalance::create(['id' => 1, 'current_balance' => 0.00]);
    }

    public function test_credit_posts_and_updates_balance()
    {
        $service = new LedgerService();

        // Build a fake top-up object (std class won't match, so use model create)
        $topUpId = DB::table('petty_cash_ledger_entries')->max('id') ?: 0;
        $topUp = new PettyCashTopUp();
        $topUp->id = 1;
        $topUp->amount = 100.00;
        $topUp->payment_method = 'cash';
        $topUp->transaction_code = null;
        $topUp->description = 'Test topup';
        $topUp->created_by = 1;
        $topUp->date_topped_up = null;

        $entry = LedgerEntry::creditForTopUp($topUp);
        $balance = $service->post($entry);

        $this->assertSame('100.00', number_format($balance->current_balance, 2, '.', ''));
        $this->assertDatabaseHas('petty_cash_ledger_entries', ['reference_number' => $entry->reference_number, 'type' => 'credit']);
    }

    public function test_debit_posts_and_updates_balance()
    {
        $service = new LedgerService();

        // Seed a balance
        DB::table('petty_cash_balances')->where('id', 1)->update(['current_balance' => 200.00]);

        $d = new PettyCashDisbursement();
        $d->id = 1;
        $d->amount = 50.00;
        $d->transaction_cost = 0.00;
        $d->receiver = 'Receiver';
        $d->payment_method = 'cash';
        $d->description = 'Test';

        $entry = LedgerEntry::debitForDisbursement($d);
        $balance = $service->post($entry);

        $this->assertSame('150.00', number_format($balance->current_balance, 2, '.', ''));
        $this->assertDatabaseHas('petty_cash_ledger_entries', ['reference_number' => $entry->reference_number, 'type' => 'debit']);
    }

    public function test_rebuild_from_ledger_resets_balance()
    {
        $service = new LedgerService();

        DB::table('petty_cash_ledger_entries')->insert([
            ['reference_number' => 'TOP-000001', 'type' => 'credit', 'amount' => '500.00', 'balance_snapshot' => '500.00', 'metadata' => '{}', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['reference_number' => 'PCR-000001', 'type' => 'debit', 'amount' => '123.45', 'balance_snapshot' => '376.55', 'metadata' => '{}', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $balance = $service->rebuildFromLedger();

        $this->assertSame('376.55', number_format($balance->current_balance, 2, '.', ''));
    }
}
