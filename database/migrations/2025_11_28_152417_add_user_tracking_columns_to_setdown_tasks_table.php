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
        Schema::table('setdown_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('setdown_tasks', 'created_by')) {
                $table->foreignId('created_by')->after('completion_notes')->constrained('users');
            }
            if (!Schema::hasColumn('setdown_tasks', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('setdown_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('setdown_tasks', 'updated_by')) {
                $table->dropForeign(['updated_by']);
                $table->dropColumn('updated_by');
            }
            if (Schema::hasColumn('setdown_tasks', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
};
