<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_reworks', function (Blueprint $table) {
            if (!Schema::hasColumn('work_order_reworks', 'assigned_workstation')) {
                $table->string('assigned_workstation', 120)->nullable()->after('status');
            }
            if (!Schema::hasColumn('work_order_reworks', 'assigned_to')) {
                $table->string('assigned_to', 190)->nullable()->after('assigned_workstation');
            }
            if (!Schema::hasColumn('work_order_reworks', 'target_date')) {
                $table->date('target_date')->nullable()->after('assigned_to');
            }
            if (!Schema::hasColumn('work_order_reworks', 'qc_status')) {
                $table->enum('qc_status', ['pending', 'passed', 'failed'])->default('pending')->after('target_date');
            }
            if (!Schema::hasColumn('work_order_reworks', 'qc_reason')) {
                $table->string('qc_reason', 255)->nullable()->after('qc_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_order_reworks', function (Blueprint $table) {
            if (Schema::hasColumn('work_order_reworks', 'qc_reason')) {
                $table->dropColumn('qc_reason');
            }
            if (Schema::hasColumn('work_order_reworks', 'qc_status')) {
                $table->dropColumn('qc_status');
            }
            if (Schema::hasColumn('work_order_reworks', 'target_date')) {
                $table->dropColumn('target_date');
            }
            if (Schema::hasColumn('work_order_reworks', 'assigned_to')) {
                $table->dropColumn('assigned_to');
            }
            if (Schema::hasColumn('work_order_reworks', 'assigned_workstation')) {
                $table->dropColumn('assigned_workstation');
            }
        });
    }
};
