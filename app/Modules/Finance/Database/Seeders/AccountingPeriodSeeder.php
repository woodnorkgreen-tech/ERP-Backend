<?php

namespace App\Modules\Finance\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Monthly accounting periods.
 *
 * Every posting path resolves a period from its posting date, so a missing
 * period is a hard stop rather than a silent null. Seeding a generous range
 * ahead of time is what keeps that guarantee cheap.
 *
 * Periods seed OPEN. Locking is a deliberate Finance action at month end, never
 * a default — a period locked by a seeder would block legitimate back-dated
 * entries nobody could explain.
 */
class AccountingPeriodSeeder extends Seeder
{
    private const FIRST_YEAR = 2024;
    private const YEARS_AHEAD = 1;

    public function run(): void
    {
        $lastYear = (int) CarbonImmutable::now()->year + self::YEARS_AHEAD;
        $now = now();

        for ($year = self::FIRST_YEAR; $year <= $lastYear; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $start = CarbonImmutable::create($year, $month, 1);

                DB::table('accounting_periods')->updateOrInsert(
                    ['year' => $year, 'month' => $month],
                    [
                        'starts_on' => $start->toDateString(),
                        'ends_on' => $start->endOfMonth()->toDateString(),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        }
    }
}
