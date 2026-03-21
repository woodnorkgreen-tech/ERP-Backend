<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();

        $defaults = [
            [
                'name' => 'Annual Leave',
                'code' => 'ANNUAL',
                'days_per_year' => 21,
                'monthly_accrual_rate' => 1.75,
                'allow_advance' => true,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Kenya statutory minimum annual leave: 21 working days, earned at 1.75 days per completed month.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
            [
                'name' => 'Sick Leave',
                'code' => 'SICK',
                'days_per_year' => 14,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Kenya statutory sick leave baseline: 7 days full pay and 7 days half pay after two months of service.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Maternity Leave',
                'code' => 'MATERNITY',
                'days_per_year' => 90,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'amber',
                'icon' => 'mdi-baby-carriage',
                'description' => 'Kenya statutory maternity leave: 3 months with full pay.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Paternity Leave',
                'code' => 'PATERNITY',
                'days_per_year' => 14,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'green',
                'icon' => 'mdi-human-male-child',
                'description' => 'Kenya statutory paternity leave: 2 weeks with full pay.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Unpaid Leave',
                'code' => 'UNPAID',
                'days_per_year' => 0,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'slate',
                'icon' => 'mdi-cash-remove',
                'description' => 'Policy-controlled unpaid leave. No statutory monthly accrual.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
        ];

        foreach ($defaults as $leaveType) {
            DB::table('leave_types')->updateOrInsert(
                ['code' => $leaveType['code']],
                array_merge($leaveType, ['updated_at' => $timestamp, 'created_at' => $timestamp])
            );
        }

        DB::table('leave_types')
            ->whereIn('code', ['PARENTAL', 'COMPOFF'])
            ->update([
                'is_active' => false,
                'updated_at' => $timestamp,
            ]);
    }

    public function down(): void
    {
        $timestamp = now();

        DB::table('leave_types')
            ->whereIn('code', ['MATERNITY', 'PATERNITY', 'UNPAID'])
            ->delete();

        DB::table('leave_types')
            ->where('code', 'ANNUAL')
            ->update([
                'name' => 'Annual Leave',
                'days_per_year' => 20,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Standard annual leave entitlement.',
                'is_active' => true,
                'requires_attachment' => false,
                'updated_at' => $timestamp,
            ]);

        DB::table('leave_types')
            ->where('code', 'SICK')
            ->update([
                'name' => 'Sick Leave',
                'days_per_year' => 10,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Leave for illness or medical recovery.',
                'is_active' => true,
                'requires_attachment' => true,
                'updated_at' => $timestamp,
            ]);

        DB::table('leave_types')->updateOrInsert(
            ['code' => 'PARENTAL'],
            [
                'name' => 'Parental Leave',
                'days_per_year' => 15,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'amber',
                'icon' => 'mdi-baby-face-outline',
                'description' => 'Parental care and bonding leave.',
                'is_active' => true,
                'requires_attachment' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        DB::table('leave_types')->updateOrInsert(
            ['code' => 'COMPOFF'],
            [
                'name' => 'Comp Off',
                'days_per_year' => 3,
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
                'color' => 'green',
                'icon' => 'mdi-calendar-check-outline',
                'description' => 'Time off in lieu of overtime or off-day work.',
                'is_active' => true,
                'requires_attachment' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }
};
