<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep these active for fund requisitions and payroll; they are not
        // goods or services ordered from a supplier.
        DB::table('expense_codes')->whereIn('code', ['DL-ALW-001', 'PF-PDM-001'])
            ->update(['is_procurable' => false]);
    }

    public function down(): void
    {
        DB::table('expense_codes')->whereIn('code', ['DL-ALW-001', 'PF-PDM-001'])
            ->update(['is_procurable' => true]);
    }
};
