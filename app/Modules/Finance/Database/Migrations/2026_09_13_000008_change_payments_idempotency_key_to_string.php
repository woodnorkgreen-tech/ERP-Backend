<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency_key column on payments was typed as `uuid` when it was
 * added to petty_cash_disbursements, but the codebase never stored a UUID
 * in it. SpendVoucherSettlementService writes "spend-voucher:{id}",
 * OfflineBatchService writes a sha256 hash, tests write arbitrary strings,
 * and PettyCashService treats it as an opaque lookup key for replay
 * protection. The column is widened to `string` so any deterministic key
 * fits, matching how every call-site already uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique()->change();
        });
    }
};
