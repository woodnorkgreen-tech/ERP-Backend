<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated finance policy values.
 *
 * Every threshold the brief names is a policy decision that will change:
 * the petty-cash per-transaction cap, the capitalisation threshold, the margin
 * warning and escalation levels, the cost-overrun alert. None of them belong in
 * a constant, and none of them may be edited in place — raising the petty-cash
 * cap must not retrospectively make last year's breaches compliant.
 *
 * So a change is a NEW ROW with a later `effective_from`, and every lookup
 * resolves against the transaction date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->json('value');
            $table->string('label');
            $table->text('description')->nullable();

            // Who signed it off. The brief requires accountant approval for rates
            // and thresholds before go-live; without this the approval is a
            // conversation nobody can produce afterwards.
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['key', 'effective_from']);
            $table->index(['key', 'effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
    }
};
