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
        if (!Schema::hasColumn('setdown_tasks', 'project_id')) {
            Schema::table('setdown_tasks', function (Blueprint $table) {
                $table->foreignId('project_id')->after('task_id')->constrained('project_enquiries')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('setdown_tasks', 'project_id')) {
            Schema::table('setdown_tasks', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
                $table->dropColumn('project_id');
            });
        }
    }
};
