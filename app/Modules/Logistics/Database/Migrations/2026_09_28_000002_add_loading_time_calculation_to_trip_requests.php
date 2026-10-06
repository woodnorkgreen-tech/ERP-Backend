<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the "work backward from the delivery deadline" requirement: given
 * when the client needs the delivery, how long loading and travel take, and
 * a safety buffer, the system computes when loading must start and when the
 * vehicle must depart — instead of a person typing a loading_time/
 * departure_time guess (that manual pair, added by the Logistics Log merge,
 * is left in place for trips that don't use auto-calculation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // The client's real deadline — date AND time, since the whole
            // point is to work backward from a specific hour, not just a day.
            $table->dateTime('required_delivery_at')->nullable()->after('required_date');

            $table->unsignedInteger('estimated_loading_minutes')->nullable()->after('required_delivery_at');
            $table->unsignedInteger('estimated_travel_minutes')->nullable()->after('estimated_loading_minutes');
            // A sensible default buffer so a trip with no explicit buffer
            // still gets some safety margin rather than none.
            $table->unsignedInteger('buffer_minutes')->default(15)->after('estimated_travel_minutes');

            // Computed and stored (not derived on the fly) so a scheduled
            // job can query "loading_start_by < now() and not started yet"
            // directly, without recomputing every row on every tick.
            $table->dateTime('loading_start_by')->nullable()->after('buffer_minutes');
            $table->dateTime('departure_by')->nullable()->after('loading_start_by');

            // Who gets the reminder and is expected to mark start/end —
            // defaults to the requester but can be reassigned (Kevin's "PO
            // in charge"). References employees, same as requested_by_id.
            $table->foreignId('loading_responsible_id')->nullable()->after('departure_by')
                ->constrained('employees')->nullOnDelete();

            // Actuals — what really happened, for the overdue comparison
            // and for a later "were we usually late" report.
            $table->dateTime('loading_started_at')->nullable()->after('loading_responsible_id');
            $table->dateTime('loading_ended_at')->nullable()->after('loading_started_at');

            // Set by the reminder/escalation command; lets the UI show a
            // status badge without recomputing the same comparison client
            // side, and lets "who's currently overdue" be one indexed query.
            $table->string('loading_alert_state', 20)->nullable()->after('loading_ended_at');
            $table->index('loading_start_by');
            $table->index('departure_by');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropForeign(['loading_responsible_id']);
            $table->dropColumn([
                'required_delivery_at',
                'estimated_loading_minutes',
                'estimated_travel_minutes',
                'buffer_minutes',
                'loading_start_by',
                'departure_by',
                'loading_responsible_id',
                'loading_started_at',
                'loading_ended_at',
                'loading_alert_state',
            ]);
        });
    }
};
