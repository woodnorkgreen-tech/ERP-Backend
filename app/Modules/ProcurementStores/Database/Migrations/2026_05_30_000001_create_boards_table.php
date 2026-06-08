<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();

            // Identity — unique trackable code, e.g. WNG-PLY-2026-0001
            $table->string('tracking_code', 30)->unique();

            // Source — which material definition this physical board is an instance of
            $table->foreignId('library_material_id')->constrained('library_materials')->cascadeOnDelete();

            // Batch — groups boards received together
            $table->string('batch_number', 100)->index();

            // Physical dimensions in mm
            $table->unsignedInteger('length')->default(2440);
            $table->unsignedInteger('width')->default(1220);
            $table->unsignedInteger('thickness')->default(18);

            // Derived area in m² — updated when dimensions change
            $table->decimal('area_m2', 10, 4)->default(0);

            // Financial value at time of ingestion; decremented proportionally for offcuts
            $table->decimal('current_value', 12, 2)->default(0);

            // State machine — values defined in config('boards.statuses')
            $table->string('status')->default('Available')->index();

            // Offcut lineage — set when this board is an offcut of a parent
            $table->unsignedBigInteger('parent_board_id')->nullable();
            $table->foreign('parent_board_id')->references('id')->on('boards')->nullOnDelete();
            $table->boolean('is_offcut')->default(false)->index();

            // Job assignment — which job this board is currently allocated to
            $table->string('assigned_job_ref')->nullable()->index();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
