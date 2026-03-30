<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
<<<<<<< HEAD
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('monthly_accrual_rate', 4, 2)->nullable()->after('days_per_year');
        });
=======
        if (!Schema::hasColumn('leave_types', 'monthly_accrual_rate')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->decimal('monthly_accrual_rate', 4, 2)->nullable()->after('days_per_year');
            });
        }
>>>>>>> hr

        DB::table('leave_types')->where('code', 'ANNUAL')->update([
            'days_per_year' => 21,
            'monthly_accrual_rate' => 1.75,
            'description' => 'Kenya statutory minimum annual leave: 21 working days, earned at 1.75 days per completed month.',
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->where('code', 'SICK')->update([
            'days_per_year' => 14,
            'monthly_accrual_rate' => null,
            'description' => 'Kenya statutory sick leave baseline: 7 days full pay and 7 days half pay after two months of service.',
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->where('code', 'MATERNITY')->update([
            'days_per_year' => 90,
            'monthly_accrual_rate' => null,
            'description' => 'Kenya statutory maternity leave: 3 months with full pay.',
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->where('code', 'PATERNITY')->update([
            'days_per_year' => 14,
            'monthly_accrual_rate' => null,
            'description' => 'Kenya statutory paternity leave: 2 weeks with full pay.',
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->where('code', 'UNPAID')->update([
            'days_per_year' => 0,
            'monthly_accrual_rate' => null,
            'description' => 'Policy-controlled unpaid leave. No statutory monthly accrual.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('leave_types')->where('code', 'ANNUAL')->update([
            'days_per_year' => 20,
<<<<<<< HEAD
=======
            'monthly_accrual_rate' => null,
>>>>>>> hr
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->where('code', 'SICK')->update([
            'days_per_year' => 10,
<<<<<<< HEAD
=======
            'monthly_accrual_rate' => null,
>>>>>>> hr
            'updated_at' => now(),
        ]);

        DB::table('leave_types')->where('code', 'UNPAID')->update([
            'days_per_year' => 30,
<<<<<<< HEAD
            'updated_at' => now(),
        ]);

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('monthly_accrual_rate');
        });
=======
            'monthly_accrual_rate' => null,
            'updated_at' => now(),
        ]);

        if (Schema::hasColumn('leave_types', 'monthly_accrual_rate')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->dropColumn('monthly_accrual_rate');
            });
        }
>>>>>>> hr
    }
};
