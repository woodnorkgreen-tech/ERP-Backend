<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 79E (P0-2): one row per press of "Confirm" that carried a client
 * request id. The row is written inside the stock movement's own transaction,
 * so it exists exactly when the stock moved. A resend with the same id finds
 * it and gets the first answer back instead of moving stock again.
 *
 * Additive only: a movement sent without an id behaves exactly as before, and
 * dropping the table (down) returns the endpoint to that behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_movement_submissions')) {
            return;
        }
        Schema::create('stock_movement_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_request_id');
            $table->string('movement_type', 20);
            $table->char('payload_hash', 64);
            $table->string('batch_number')->nullable();
            $table->json('log_ids')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id'], 'stock_mvmt_submissions_user_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_submissions');
    }
};
