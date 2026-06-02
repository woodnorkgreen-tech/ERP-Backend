<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_requests', function (Blueprint $table) {
            $table->id();

            // Auto-generated code e.g. TREQ-2026-0001
            $table->string('request_code')->unique();

            // Context: linked to a project or general/other
            $table->enum('context_type', ['project', 'other'])->default('other');
            $table->foreignId('project_id')
                  ->nullable()
                  ->constrained('projects')
                  ->nullOnDelete();

            // Type label — e.g. "Project Delivery", "Office Supply Run"
            $table->string('delivery_type_label');

            // Who requested
            $table->foreignId('requested_by_id')
                  ->constrained('employees')
                  ->cascadeOnDelete();

            // Priority
            $table->enum('priority', ['low', 'medium', 'high', 'emergency'])->default('medium');

            // Locations (Google Maps resolved)
            $table->string('pickup_location');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            $table->string('destination');
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();

            // Scheduling
            $table->date('required_date');
            $table->text('notes')->nullable();

            // Workflow status
            $table->enum('status', [
                'requested',
                'approved',
                'rejected',
                'assigned',
                'in_transit',
                'completed',
                'cancelled',
            ])->default('requested');

            // Approval
            $table->foreignId('approved_by_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Assignment (logistics team assigns driver + vehicle)
            $table->foreignId('assigned_driver_id')
                  ->nullable()
                  ->constrained('drivers')
                  ->nullOnDelete();
            $table->foreignId('assigned_vehicle_id')
                  ->nullable()
                  ->constrained('vehicles')
                  ->nullOnDelete();
            $table->foreignId('assigned_by_id')
                  ->nullable()
                  ->constrained('employees')
                  ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->text('assignment_notes')->nullable();

            // Trip tracking
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_requests');
    }
};
