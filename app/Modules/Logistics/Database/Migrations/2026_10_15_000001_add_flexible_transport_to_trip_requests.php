<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // Some trips need no loading at all (site visits, people only, or a
            // delivery where the goods are already on the vehicle).
            $table->boolean('loading_required')->default(true)->after('trip_kind');

            // transport_arrangement = 'external' → the lead booked an Uber / Bolt
            // / hired vehicle instead of one of our cars.
            $table->string('external_provider', 60)->nullable()->after('vehicle_note');
            $table->string('external_ref', 150)->nullable()->after('external_provider');
            $table->decimal('external_cost', 10, 2)->nullable()->after('external_ref');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn(['loading_required', 'external_provider', 'external_ref', 'external_cost']);
        });
    }
};
