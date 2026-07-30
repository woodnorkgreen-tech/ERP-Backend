<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task_issues', 'assigned_to')) {
            return;
        }

        Schema::table('task_issues', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['assigned_to']);
            $table->dropColumn('assigned_to');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_issues', 'assigned_to')) {
            return;
        }

        Schema::table('task_issues', function (Blueprint $table) {
            $table->foreignId('assigned_to')
                ->nullable()
                ->after('reported_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('assigned_to');
        });
    }
};
