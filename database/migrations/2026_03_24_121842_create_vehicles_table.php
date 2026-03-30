<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('vehicle_id')->unique();
            $table->string('plate_number')->unique();

            // Classification
            $table->enum('vehicle_type', [
                'truck',
                'van',
                'pickup',
                'motorcycle',
                'trailer',
                'other',
            ]);

            // Specs
            $table->decimal('capacity_kg', 10, 2);
            $table->enum('fuel_type', [
                'diesel',
                'petrol',
                'electric',
                'hybrid',
            ])->default('diesel');

            // Compliance
            $table->date('insurance_expiry');
            $table->decimal('odometer_km', 10, 2)->default(0);

            // Tracking
            $table->enum('gps_status', ['active', 'inactive'])->default('inactive');
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->timestamp('gps_last_updated')->nullable();

            // Status
            $table->enum('status', [
                'active',
                'inactive',
                'maintenance',
                'booked',
            ])->default('active');

            // Assigned driver (loose link)
            $table->foreignId('assigned_driver_id')
                  ->nullable()
                  ->constrained('drivers')
                  ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
