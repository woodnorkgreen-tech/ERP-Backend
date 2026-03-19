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
                'days_per_year' => 20,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Standard annual leave entitlement.',
                'is_active' => true,
                'requires_attachment' => false,
            ],
            [
                'name' => 'Sick Leave',
                'code' => 'SICK',
                'days_per_year' => 10,
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Leave for illness or medical recovery.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Maternity Leave',
                'code' => 'MATERNITY',
                'days_per_year' => 90,
                'color' => 'amber',
                'icon' => 'mdi-baby-carriage',
                'description' => 'Leave for maternity and post-delivery recovery.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Paternity Leave',
                'code' => 'PATERNITY',
                'days_per_year' => 14,
                'color' => 'green',
                'icon' => 'mdi-human-male-child',
                'description' => 'Leave for fathers after childbirth.',
                'is_active' => true,
                'requires_attachment' => true,
            ],
            [
                'name' => 'Unpaid Leave',
                'code' => 'UNPAID',
                'days_per_year' => 30,
                'color' => 'slate',
                'icon' => 'mdi-cash-remove',
                'description' => 'Approved leave taken without pay.',
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
