<?php

namespace Tests\Feature\PettyCash;

use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceTransactionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable foreign key checks to allow dropping/creating tables used in other tests
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        Schema::dropIfExists('petty_cash_balances');
        Schema::create('petty_cash_balances', function ($table) {
            $table->id();
            $table->decimal('current_balance', 10, 2)->default(0.00);
            $table->unsignedBigInteger('last_transaction_id')->nullable();
            $table->string('last_transaction_type')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::dropIfExists('petty_cash_top_ups');
        Schema::create('petty_cash_top_ups', function ($table) {
            $table->id();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->decimal('previous_balance', 10, 2)->default(0.00);
            $table->date('date_topped_up')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_code')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::dropIfExists('petty_cash_disbursements');
        Schema::create('petty_cash_disbursements', function ($table) {
            $table->id();
            $table->unsignedBigInteger('top_up_id')->nullable();
            $table->string('receiver')->nullable();
            $table->string('account')->nullable();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->text('description')->nullable();
            $table->string('project_name')->nullable();
            $table->string('venue')->nullable();
            $table->string('classification')->nullable();
            $table->string('job_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_code')->nullable();
            $table->string('status')->default('active');
            $table->string('void_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->decimal('tax', 10, 2)->default(0.00);
            $table->date('date_disbursed')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->unsignedBigInteger('requisition_id')->nullable();
            $table->decimal('transaction_cost', 10, 2)->default(0.00);
            $table->string('budget_category')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::dropIfExists('petty_cash_ledger_entries');
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

        Schema::dropIfExists('petty_cash_activity_logs');
        Schema::create('petty_cash_activity_logs', function ($table) {

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('description')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        PettyCashBalance::create([
            'id' => 1,
            'current_balance' => 50.00,
            'last_transaction_id' => null,
            'last_transaction_type' => null,
            'updated_at' => now(),
        ]);
    }

    public function test_insufficient_balance_does_not_leave_an_open_transaction(): void
    {
        $service = new PettyCashService(new PettyCashRepository());
        $transactionLevelBeforeCall = DB::transactionLevel();

        $result = $service->createDisbursement([
            'amount' => 100.00,
            'transaction_cost' => 0.00,
            'receiver' => 'Test Receiver',
            'account' => 'Test Account',
            'description' => 'Test',
            'payment_method' => 'cash',
            'classification' => 'operations',
            'created_by' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame($transactionLevelBeforeCall, DB::transactionLevel());
        $this->assertSame(0, DB::table('petty_cash_ledger_entries')->count());
    }
}
