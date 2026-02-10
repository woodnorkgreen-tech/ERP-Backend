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
        // Drop FK constraint to employees and make worker_id a plain unsigned big integer
        Schema::table('job_cards', function (Blueprint $table) {
            // Drop the existing foreign key if present
            try {
                $table->dropForeign(['worker_id']);
            } catch (\Throwable $e) {
                // FK might not exist in some environments; ignore
            }

            // Ensure column type is unsignedBigInteger without FK constraint
            $table->unsignedBigInteger('worker_id')->change();
            // Optional: keep an index for lookups
            try {
                $table->index('worker_id');
            } catch (\Throwable $e) {
                // Index may already exist; ignore
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            // Best-effort revert: re-add FK to employees table
            try {
                $table->dropIndex(['worker_id']);
            } catch (\Throwable $e) {
                // Index might not exist; ignore
            }

            // Change back to foreignId referencing employees
            $table->foreign('worker_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }
};
