<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft deletes to the users table.
 *
 * UserController::destroy() previously hard-deleted the row, orphaning every
 * foreign key that references the user (action_logs.user_id, created_by /
 * approved_by columns across modules, etc.). Soft-deleting retains the record
 * for referential integrity and audit history while removing the user from
 * default queries, and makes the action reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
