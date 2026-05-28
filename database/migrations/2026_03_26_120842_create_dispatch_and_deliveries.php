<?php
// ─── 1. dispatch_batches ─────────────────────────────────────────────────
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_batches', function (Blueprint $table) {
            $table->id();

            // Auto-generated: DSPB-2026-0001
            $table->string('batch_code')->unique();

            // When is this batch going out
            $table->date('dispatch_date');
            $table->time('departure_time')->nullable();

            // Assigned resources
            $table->foreignId('driver_id')
                  ->nullable()
                  ->constrained('drivers')
                  ->nullOnDelete();
            $table->foreignId('vehicle_id')
                  ->nullable()
                  ->constrained('vehicles')
                  ->nullOnDelete();

            // Who built this batch
            $table->foreignId('created_by_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();

            // Status workflow
            $table->enum('status', [
                'draft',        // being built
                'confirmed',    // locked in, delivery created
                'in_transit',   // driver departed
                'completed',    // all stops done
                'cancelled',
            ])->default('draft');

            $table->text('notes')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── 2. Link trip_requests to batches (pivot column) ──────────────
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->foreignId('batch_id')
                  ->nullable()
                  ->after('status')
                  ->constrained('dispatch_batches')
                  ->nullOnDelete();
            $table->integer('stop_order')->nullable()->after('batch_id'); // stop sequence in the batch
        });

        // ── 3. deliveries ────────────────────────────────────────────────
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();

            // Auto-generated: DEL-2026-0001
            $table->string('delivery_code')->unique();

            // One delivery per batch (or standalone single-stop)
            $table->foreignId('batch_id')
                  ->nullable()
                  ->constrained('dispatch_batches')
                  ->nullOnDelete();

            // Denormalised for easy display
            $table->foreignId('driver_id')
                  ->nullable()
                  ->constrained('drivers')
                  ->nullOnDelete();
            $table->foreignId('vehicle_id')
                  ->nullable()
                  ->constrained('vehicles')
                  ->nullOnDelete();

            $table->integer('total_stops')->default(1);
            $table->integer('completed_stops')->default(0);

            $table->enum('status', [
                'pending',
                'in_transit',
                'partial',
                'delivered',
                'failed',
            ])->default('pending');

            $table->date('delivery_date');
            $table->time('departure_time')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── 4. delivery_stops (one row per trip_request in a delivery) ───
        Schema::create('delivery_stops', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')
                  ->constrained('deliveries')
                  ->cascadeOnDelete();
            $table->foreignId('trip_request_id')
                  ->constrained('trip_requests')
                  ->cascadeOnDelete();

            $table->integer('stop_order')->default(1);

            $table->string('location');          // destination from trip_request
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone')->nullable();

            $table->enum('status', [
                'pending',
                'en_route',
                'delivered',
                'failed',
            ])->default('pending');

            // Proof of delivery
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_stops');
        Schema::dropIfExists('deliveries');
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn(['batch_id', 'stop_order']);
        });
        Schema::dropIfExists('dispatch_batches');
    }
};
