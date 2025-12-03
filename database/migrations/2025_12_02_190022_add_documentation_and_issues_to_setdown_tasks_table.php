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
            // Add documentation column (JSON) to store photos, notes, and other documentation
            if (!Schema::hasColumn('setdown_tasks', 'documentation')) {
                $table->json('documentation')->nullable()->after('completion_notes');
            }
            
            // Add issues column (JSON) to store issues/problems encountered
            if (!Schema::hasColumn('setdown_tasks', 'issues')) {
                $table->json('issues')->nullable()->after('documentation');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('setdown_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('setdown_tasks', 'issues')) {
                $table->dropColumn('issues');
            }
            if (Schema::hasColumn('setdown_tasks', 'documentation')) {
                $table->dropColumn('documentation');
            }
        });
    }
};
