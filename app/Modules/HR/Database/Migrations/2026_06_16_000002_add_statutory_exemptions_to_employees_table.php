<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-employee statutory deduction exemptions. Holds an array of codes
     * (paye, nssf, shif, housing_levy) the employee is NOT subject to during
     * payroll runs. Null/empty means fully subject to all statutory deductions.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->json('statutory_exemptions')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('statutory_exemptions');
        });
    }
};
