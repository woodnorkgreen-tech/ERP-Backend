<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('work_orders') || Schema::hasColumn('work_orders', 'project_id')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('project_enquiry_id');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('work_orders') || !Schema::hasColumn('work_orders', 'project_id')) {
            return;
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
