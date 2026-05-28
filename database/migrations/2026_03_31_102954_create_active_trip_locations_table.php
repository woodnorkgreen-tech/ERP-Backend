<?php
// Add to your existing deliveries table migration OR run as a new migration
// File: 2024_01_03_add_driver_tracking_to_deliveries.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Add real-time GPS tracking columns to delivery_stops ──────────
        Schema::table('delivery_stops', function (Blueprint $table) {
            // Driver confirmed arrival / departure timestamps
            $table->string('failure_reason')->nullable()->after('delivery_note');
        });

        // ── Active trip tracking table ─────────────────────────────────────
        // This stores the driver's live GPS position for the active delivery
        // Reuses your existing locations table pattern but linked to delivery
        Schema::create('active_trip_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // driver's user_id
            $table->string('latitude');
            $table->string('longitude');
            $table->decimal('speed_kmh', 6, 2)->default(0);
            $table->enum('vehicle_status', ['moving', 'idle', 'stopped'])->default('idle');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_trip_locations');
        Schema::table('delivery_stops', function (Blueprint $table) {
            $table->dropColumn('failure_reason');
        });
    }
};
