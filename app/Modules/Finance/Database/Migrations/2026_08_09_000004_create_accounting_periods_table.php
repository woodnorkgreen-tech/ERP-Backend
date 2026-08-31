<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Period control.
 *
 * Built now, before anything posts, because every write path has to consult it
 * and retrofitting that later means revisiting all of them. A cost line carries
 * its period from the day it is captured, even though no journal is written
 * until the GL phase.
 *
 * The rule it enforces: once Finance locks a month, nothing may be written into
 * it. A correction to a locked period is a reversal dated in the current open
 * period — never an edit to history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('starts_on');
            $table->date('ends_on');

            // `closed` is terminal; `locked` may be reopened by an authorised
            // reversal approval, which is why the reopen trail is recorded.
            $table->enum('status', ['open', 'locked', 'closed'])->default('open');

            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index('status');
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
