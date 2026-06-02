<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enrich live GPS pings with route data
        Schema::table('active_trip_locations', function (Blueprint $table) {
            $table->decimal('distance_to_next_stop_km', 8, 3)->nullable()->after('speed_kmh');
            $table->integer('eta_minutes')->nullable()->after('distance_to_next_stop_km');
            $table->integer('traffic_delay_minutes')->nullable()->after('eta_minutes');
            $table->text('route_polyline')->nullable()->after('traffic_delay_minutes');
            $table->integer('next_stop_id')->nullable()->after('route_polyline');
        });

        // Per-stop completed segment analytics
        Schema::table('delivery_stops', function (Blueprint $table) {
            $table->decimal('distance_from_prev_km', 8, 3)->nullable()->after('lng');
            $table->integer('scheduled_eta_minutes')->nullable()->after('distance_from_prev_km');
            $table->integer('actual_duration_minutes')->nullable()->after('scheduled_eta_minutes');
            $table->integer('arrival_delta_minutes')->nullable()->after('actual_duration_minutes'); // negative = early, positive = late
            $table->boolean('traffic_encountered')->default(false)->after('arrival_delta_minutes');
        });

        // Delivery-level summary (filled when delivery completes)
        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('total_km', 8, 3)->nullable()->after('completed_at');
            $table->integer('total_duration_minutes')->nullable()->after('total_km');
            $table->decimal('avg_speed_kmh', 5, 2)->nullable()->after('total_duration_minutes');
            $table->boolean('on_time')->nullable()->after('avg_speed_kmh');
        });
    }

    public function down(): void
    {
        Schema::table('active_trip_locations', function (Blueprint $table) {
            $table->dropColumn(['distance_to_next_stop_km', 'eta_minutes', 'traffic_delay_minutes', 'route_polyline', 'next_stop_id']);
        });
        Schema::table('delivery_stops', function (Blueprint $table) {
            $table->dropColumn(['distance_from_prev_km', 'scheduled_eta_minutes', 'actual_duration_minutes', 'arrival_delta_minutes', 'traffic_encountered']);
        });
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['total_km', 'total_duration_minutes', 'avg_speed_kmh', 'on_time']);
        });
    }
};
