<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes "the client has not given us a date yet" an explicit, recorded state.
 *
 * A null expected_delivery_date already rendered as "TBC" in the UI, but it was
 * indistinguishable from a field nobody had filled in, so nothing could track it
 * and such enquiries quietly went stale. delivery_date_status records the intent
 * and delivery_date_tbc_since records when the clock started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->enum('delivery_date_status', ['confirmed', 'tbc'])
                ->default('confirmed')
                ->after('expected_delivery_date');
            $table->date('delivery_date_tbc_since')
                ->nullable()
                ->after('delivery_date_status');
        });

        // Backfill: every enquiry without a date is, by definition, TBC today.
        // date_received is the honest start of the clock — it is when we first
        // had the conversation in which the date was not given.
        DB::table('project_enquiries')
            ->whereNull('expected_delivery_date')
            ->update([
                'delivery_date_status'    => 'tbc',
                'delivery_date_tbc_since' => DB::raw('COALESCE(date_received, DATE(created_at))'),
            ]);
    }

    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropColumn(['delivery_date_status', 'delivery_date_tbc_since']);
        });
    }
};
