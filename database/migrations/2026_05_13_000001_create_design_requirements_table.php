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
        Schema::create('design_requirements', function (Blueprint $col) {
            $col->id();
            $col->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $col->string('category');
            $col->text('description')->nullable();
            $col->enum('status', ['pending', 'fulfilled', 'approved', 'rejected'])->default('pending');
            $col->foreignId('asset_id')->nullable()->constrained('design_assets')->onDelete('set null');
            $col->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('design_requirements');
    }
};
