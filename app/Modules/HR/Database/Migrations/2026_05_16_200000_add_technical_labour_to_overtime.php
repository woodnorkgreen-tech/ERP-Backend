<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. OT Entries
        Schema::table('ot_entries', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
            $table->foreignId('technical_labour_id')->nullable()->constrained('technical_labours')->nullOnDelete();
        });

        // 2. Ledger Entries
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
            $table->foreignId('technical_labour_id')->nullable()->constrained('technical_labours')->nullOnDelete();
        });

        // 3. Compensations
        Schema::table('compensations', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
            $table->foreignId('technical_labour_id')->nullable()->constrained('technical_labours')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compensations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_labour_id');
            $table->foreignId('employee_id')->nullable(false)->change();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_labour_id');
            $table->foreignId('employee_id')->nullable(false)->change();
        });

        Schema::table('ot_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_labour_id');
            $table->foreignId('employee_id')->nullable(false)->change();
        });
    }
};
