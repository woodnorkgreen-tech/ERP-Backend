<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')
            ->where('code', 'ANNUAL')
            ->update([
                'days_per_year' => 21,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'description' => 'Annual leave entitlement: 21 working days per calendar year.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('leave_types')
            ->where('code', 'ANNUAL')
            ->update([
                'monthly_accrual_rate' => 1.75,
                'allow_advance' => true,
                'description' => 'Kenya statutory minimum annual leave: 21 working days, earned at 1.75 days per completed month.',
                'updated_at' => now(),
            ]);
    }
};
