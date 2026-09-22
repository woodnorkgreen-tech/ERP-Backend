<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignId('locked_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->after('locked_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropConstrainedForeignId('paid_by');
        });
    }
};
