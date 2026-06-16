<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disciplinary cases key off the SUBJECT employee, but `employee_id` was wired
 * as a FK to `users` (and the model/validation treated it as a user id). That
 * mismatched the column name, the frontend (which sends an Employee id) and the
 * disciplinary-letter document (which needs Employee fields). Repoint the FK to
 * `employees`. Safe: the table has no rows at the time of this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disciplinary_cases', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('disciplinary_cases', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->foreign('employee_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
