<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folds the standalone Logistics Log's fields onto trip_requests so a single
 * record carries everything the Log used to track separately: who is
 * arranging transport, when loading/departure/set-down happen, and a free
 * text note for the "client picks up themselves" case the Log's vehicle
 * dropdown covered with a literal "Client to pick" option.
 *
 * Column choices deliberately mirror existing conventions already used
 * elsewhere in this module (Delivery::departure_time / DispatchBatch::
 * departure_time are plain 'HH:MM' strings, not datetimes) so the same
 * pattern reads the same way everywhere in Logistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // 'company' = Logistics arranges driver+vehicle (the normal Trip
            // Request flow); 'client' = the client/project team collects it
            // themselves, so no driver/vehicle assignment is expected.
            $table->string('transport_arrangement', 20)
                ->default('company')
                ->after('context_type');

            // Free text describing the pickup arrangement when
            // transport_arrangement = 'client' (who's collecting, on what
            // vehicle) — replaces the Log's hardcoded "Client to pick" entry.
            $table->string('vehicle_note', 150)->nullable()->after('assigned_vehicle_id');

            // Planning timeline, filled in ahead of the trip. Same string
            // format as the existing departure_time fields on Delivery /
            // DispatchBatch for consistency.
            $table->string('loading_time', 20)->nullable()->after('required_date');
            $table->string('departure_time', 20)->nullable()->after('loading_time');

            // Set-down / return time at the venue. Kept as a nullable
            // datetime (matches the Log's original field) rather than a bare
            // string, since "to be communicated" is represented by leaving
            // it null and noting that fact in `notes` — exactly how the Log
            // itself handled this to avoid an invalid-date validation error.
            $table->dateTime('setdown_time')->nullable()->after('departure_time');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn([
                'transport_arrangement',
                'vehicle_note',
                'loading_time',
                'departure_time',
                'setdown_time',
            ]);
        });
    }
};
