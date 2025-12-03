<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('logistics_task_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->onDelete('cascade');
            
            // Logistics-specific fields
            $table->string('transport_type')->nullable(); // e.g., 'truck', 'van', 'cargo'
            $table->json('transport_items')->nullable(); // Array of items being transported
            $table->json('checklist_items')->nullable(); // Array of checklist items
            $table->string('pickup_location')->nullable();
            $table->string('delivery_location')->nullable();
            $table->dateTime('scheduled_pickup_time')->nullable();
            $table->dateTime('scheduled_delivery_time')->nullable();
            $table->dateTime('actual_pickup_time')->nullable();
            $table->dateTime('actual_delivery_time')->nullable();
            $table->string('vehicle_registration')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('special_instructions')->nullable();
            $table->decimal('estimated_distance_km', 8, 2)->nullable();
            $table->decimal('actual_distance_km', 8, 2)->nullable();
            $table->string('cargo_type')->nullable(); // e.g., 'fragile', 'heavy', 'standard'
            $table->decimal('cargo_weight_kg', 10, 2)->nullable();
            $table->integer('cargo_volume_m3')->nullable();
            $table->boolean('requires_signature')->default(false);
            $table->string('signature_path')->nullable(); // Path to signature image
            $table->json('photos')->nullable(); // Array of photo paths
            $table->text('delivery_notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('transport_type');
            $table->index('scheduled_pickup_time');
            $table->index('scheduled_delivery_time');
            $table->index('driver_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_task_contexts');
    }
};
