<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('task_experience_logs', 'tags')) {
            Schema::table('task_experience_logs', function (Blueprint $table) {
                $table->dropColumn('tags');
            });
        }

        if (Schema::hasColumn('task_templates', 'tags')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->dropColumn('tags');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('task_experience_logs', 'tags')) {
            Schema::table('task_experience_logs', function (Blueprint $table) {
                $table->json('tags')->nullable()->after('log_type');
            });
        }

        if (!Schema::hasColumn('task_templates', 'tags')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->json('tags')->nullable()->after('updated_by');
            });
        }
    }
};
