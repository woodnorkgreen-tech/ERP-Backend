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
        Schema::create('site_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->date('site_visit_date');
            $table->enum('status', ['pending', 'completed', 'approved', 'rejected'])->nullable();
            $table->string('project_manager')->nullable();
            $table->string('other_project_manager')->nullable();
            $table->string('client_name');
            $table->string('location');
            $table->json('attendees')->nullable();
            $table->string('client_contact_person')->nullable();
            $table->string('client_phone', 20)->nullable();
            $table->string('client_email')->nullable();
            $table->text('project_description');
            $table->text('objectives')->nullable();
            $table->text('current_condition')->nullable();
            $table->text('existing_branding')->nullable();
            $table->text('access_logistics')->nullable();
            $table->text('parking_availability')->nullable();
            $table->text('size_accessibility')->nullable();
            $table->string('lifts')->nullable();
            $table->string('door_sizes')->nullable();
            $table->text('loading_areas')->nullable();
            $table->text('site_measurements')->nullable();
            $table->string('room_size')->nullable();
            $table->text('constraints')->nullable();
            $table->text('electrical_outlets')->nullable();
            $table->text('food_refreshment')->nullable();
            $table->text('branding_preferences')->nullable();
            $table->text('material_preferences')->nullable();
            $table->string('color_scheme')->nullable();
            $table->text('brand_guidelines')->nullable();
            $table->text('special_instructions')->nullable();
            $table->datetime('project_start_date')->nullable();
            $table->datetime('project_deadline')->nullable();
            $table->text('milestones')->nullable();
            $table->text('safety_conditions')->nullable();
            $table->text('potential_hazards')->nullable();
            $table->text('safety_requirements')->nullable();
            $table->text('additional_notes')->nullable();
            $table->text('special_requests')->nullable();
            $table->json('action_items')->nullable();
            $table->string('prepared_by')->nullable();
            $table->text('prepared_signature')->nullable();
            $table->date('prepared_date')->nullable();
            $table->boolean('client_approval')->nullable();
            $table->text('client_signature')->nullable();
            $table->date('client_approval_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_surveys');
    }
};
