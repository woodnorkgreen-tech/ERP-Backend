<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen employees.status to include 'suspended'.
 *
 * UpdateEmployeeRequest now accepts status = 'suspended' (e.g. for staff under
 * active disciplinary suspension), but the column enum only allowed
 * active / inactive / terminated / on-leave. Without this, writing 'suspended'
 * errors under MySQL strict mode (or is silently truncated otherwise).
 *
 * Mirrors database/migrations/2026_03_24_064005_add_on_leave_to_employee_status.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE employees MODIFY COLUMN status ENUM('active', 'inactive', 'terminated', 'on-leave', 'suspended') DEFAULT 'active'");
    }

    public function down(): void
    {
        // Normalise any suspended rows before narrowing the enum, otherwise the
        // ALTER fails / corrupts those values.
        DB::table('employees')->where('status', 'suspended')->update(['status' => 'inactive']);

        DB::statement("ALTER TABLE employees MODIFY COLUMN status ENUM('active', 'inactive', 'terminated', 'on-leave') DEFAULT 'active'");
    }
};
