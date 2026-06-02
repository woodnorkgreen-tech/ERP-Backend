<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->id();

            // Auto-generated: MNT-2026-0001
            $table->string('log_code')->unique();

            // Vehicle being serviced
            $table->foreignId('vehicle_id')
                  ->constrained('vehicles')
                  ->cascadeOnDelete();

            // Who had the vehicle at time of log
            $table->foreignId('driver_on_duty_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();

            // Who logged this entry
            $table->foreignId('logged_by_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();

            // Activity details
            $table->enum('activity_type', [
                'maintenance',
                'repair',
                'inspection',
                'fuel',
            ])->default('maintenance');

            $table->enum('maintenance_type', [
                'routine',
                'emergency',
            ])->default('routine');

            // OD reading at time of log
            $table->decimal('odometer_reading', 10, 2)->nullable();

            // What was done — free text description
            $table->text('description');

            // Cause of failure (if repair/emergency)
            $table->enum('cause_of_failure', [
                'wear_and_tear',
                'accident',
                'negligence',
                'unknown',
                'routine_check',
                'other',
            ])->nullable();

            // Service details (filled by Procurement)
            $table->text('service_provider')->nullable();
            $table->text('cost_breakdown')->nullable();   // JSON: [{ item, cost }]
            $table->decimal('total_cost', 12, 2)->nullable();

            // Scheduling
            $table->date('service_date')->nullable();
            $table->integer('downtime_days')->nullable();
            $table->date('next_service_due')->nullable();
            $table->text('next_service_notes')->nullable();

            // Approval workflow
            $table->enum('status', [
                'draft',            // just logged, vehicle blocked
                'submitted',        // sent to procurement
                'costed',           // procurement added cost/vendor
                'approved',         // finance/admin approved
                'completed',        // logistics lead confirmed work done
                'rejected',         // rejected at any stage
            ])->default('draft');

            // Who approved / rejected
            $table->foreignId('approved_by_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Logistics lead confirmation
            $table->foreignId('confirmed_by_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // Photos stored as JSON array of paths
            $table->json('before_photos')->nullable();
            $table->json('after_photos')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenance_logs');
    }
};
