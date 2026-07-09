<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();
        $hasMonthlyAccrualRate = Schema::hasColumn('leave_types', 'monthly_accrual_rate');
        $hasAllowAdvance = Schema::hasColumn('leave_types', 'allow_advance');

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
                'description' => 'Kenya statutory sick leave baseline: 7 full-pay days and 7 half-pay days (total 14 days); half-pay applies after two months of service.',
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
                'requires_attachment' => false,
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
                'requires_attachment' => false,
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
            if (!$hasMonthlyAccrualRate) {
                unset($leaveType['monthly_accrual_rate']);
            }

            if (!$hasAllowAdvance) {
                unset($leaveType['allow_advance']);
            }

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
        $hasMonthlyAccrualRate = Schema::hasColumn('leave_types', 'monthly_accrual_rate');
        $hasAllowAdvance = Schema::hasColumn('leave_types', 'allow_advance');

        DB::table('leave_types')
            ->whereIn('code', ['MATERNITY', 'PATERNITY', 'UNPAID'])
            ->delete();

        $annualUpdate = [
            'name' => 'Annual Leave',
            'days_per_year' => 20,
            'color' => 'emerald',
            'icon' => 'mdi-palm-tree',
            'description' => 'Standard annual leave entitlement.',
            'is_active' => true,
            'requires_attachment' => false,
            'updated_at' => $timestamp,
        ];

        if ($hasMonthlyAccrualRate) {
            $annualUpdate['monthly_accrual_rate'] = null;
        }

        if ($hasAllowAdvance) {
            $annualUpdate['allow_advance'] = false;
        }

        DB::table('leave_types')
            ->where('code', 'ANNUAL')
            ->update($annualUpdate);

        $sickUpdate = [
            'name' => 'Sick Leave',
            'days_per_year' => 10,
            'color' => 'blue',
            'icon' => 'mdi-medical-bag',
            'description' => 'Leave for illness or medical recovery.',
            'is_active' => true,
            'requires_attachment' => true,
            'updated_at' => $timestamp,
        ];

        if ($hasMonthlyAccrualRate) {
            $sickUpdate['monthly_accrual_rate'] = null;
        }

        if ($hasAllowAdvance) {
            $sickUpdate['allow_advance'] = false;
        }

        DB::table('leave_types')
            ->where('code', 'SICK')
            ->update($sickUpdate);

        $parentalPayload = [
            'name' => 'Parental Leave',
            'days_per_year' => 15,
            'color' => 'amber',
            'icon' => 'mdi-baby-face-outline',
            'description' => 'Parental care and bonding leave.',
            'is_active' => true,
            'requires_attachment' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($hasMonthlyAccrualRate) {
            $parentalPayload['monthly_accrual_rate'] = null;
        }

        if ($hasAllowAdvance) {
            $parentalPayload['allow_advance'] = false;
        }

        DB::table('leave_types')->updateOrInsert(
            ['code' => 'PARENTAL'],
            $parentalPayload
        );

        $compOffPayload = [
            'name' => 'Comp Off',
            'days_per_year' => 3,
            'color' => 'green',
            'icon' => 'mdi-calendar-check-outline',
            'description' => 'Time off in lieu of overtime or off-day work.',
            'is_active' => true,
            'requires_attachment' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($hasMonthlyAccrualRate) {
            $compOffPayload['monthly_accrual_rate'] = null;
        }

        if ($hasAllowAdvance) {
            $compOffPayload['allow_advance'] = false;
        }

        DB::table('leave_types')->updateOrInsert(
            ['code' => 'COMPOFF'],
            $compOffPayload
        );
    }
};
