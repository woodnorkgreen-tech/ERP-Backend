<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Task-level "skipped" has been removed: status changes now flow through the
     * single source of truth (EnquiryWorkflowService) which only emits
     * pending / in_progress / completed / cancelled.
     */
    public function up(): void
    {
        // 1. Collapse existing 'skipped' rows into 'completed' BEFORE dropping the
        //    enum value (skip historically meant "resolved / done").
        DB::table('enquiry_tasks')
            ->where('status', 'skipped')
            ->update([
                'status'     => 'completed',
                'updated_at' => now(),
            ]);

        // 2. Drop 'skipped' from the status enum.
        DB::statement("ALTER TABLE enquiry_tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending'");
    }

    /**
     * Reverse: re-add the enum value. Collapsed rows are NOT restored — they stay
     * 'completed' (the conversion is one-way).
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE enquiry_tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled', 'skipped') DEFAULT 'pending'");
    }
};
