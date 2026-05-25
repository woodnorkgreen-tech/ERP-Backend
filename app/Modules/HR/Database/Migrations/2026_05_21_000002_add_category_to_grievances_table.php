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
        Schema::table('grievances', function (Blueprint $table) {
            if (!Schema::hasColumn('grievances', 'category')) {
                $table->enum('category', [
                    'Compensation & Benefits',
                    'Workplace Health & Safety',
                    'Bullying, Harassment & Discrimination',
                    'Performance & Disciplinary Actions',
                    'Work Assignments & Workloads',
                ])->nullable()->after('description');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grievances', function (Blueprint $table) {
            if (Schema::hasColumn('grievances', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};
