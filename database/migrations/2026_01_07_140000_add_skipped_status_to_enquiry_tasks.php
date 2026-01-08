<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the status enum to include 'skipped'
        // Using raw SQL as Doctrine DBAL often has issues with ENUMs
        DB::statement("ALTER TABLE enquiry_tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled', 'skipped') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to previous enum list
        // Note: Data with 'skipped' status might cause issues when reverting
        DB::statement("ALTER TABLE enquiry_tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending'");
    }
};
