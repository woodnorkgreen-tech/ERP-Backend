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
        Schema::create('logistics_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->onDelete('cascade');
            $table->string('site');
            $table->datetime('loading_time');
            $table->datetime('departure');
            $table->string('vehicle_allocated');
            $table->string('project_officer_incharge');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_log_entries');
    }
};
