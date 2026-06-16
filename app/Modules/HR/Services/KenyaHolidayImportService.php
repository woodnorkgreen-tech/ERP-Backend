<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceHoliday;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KenyaHolidayImportService
{
    public function importYear(int $year): int
    {
        $url = str_replace('{year}', (string) $year, (string) config('attendance.holiday_api_url'));
        $request = Http::timeout(30)->retry(3, 500);
        $caBundle = config('attendance.holiday_ca_bundle');

        if (is_string($caBundle) && is_file($caBundle)) {
            $request = $request->withOptions(['verify' => $caBundle]);
        }

        $response = $request->get($url);
        $response->throw();

        $holidays = $response->json();
        if (!is_array($holidays)) {
            throw new \RuntimeException('The Kenyan holiday feed returned an invalid response.');
        }

        $rows = collect($holidays)
            ->filter(fn ($holiday) => is_array($holiday) && !empty($holiday['date']) && !empty($holiday['name']))
            ->map(fn ($holiday) => [
                'date' => $holiday['date'],
                'name' => $holiday['localName'] ?? $holiday['name'],
                'source' => 'nager_date',
                'source_reference' => $url,
            ])
            ->concat(config("attendance.kenya_holiday_overrides.{$year}", []))
            ->unique('date');

        foreach ($rows as $holiday) {
            $savedHoliday = AttendanceHoliday::query()->updateOrCreate(
                ['date' => $holiday['date']],
                [
                    'name' => $holiday['name'],
                    'source' => $holiday['source'] ?? 'kenya_gazette',
                    'source_reference' => $holiday['source_reference'] ?? null,
                    'imported_at' => now(),
                    'is_active' => true,
                ]
            );

            if (Schema::hasColumn('attendance_records', 'is_holiday_work')) {
                DB::table('attendance_records')
                    ->whereDate('date', $savedHoliday->date)
                    ->whereNotNull('clock_in')
                    ->update([
                        'is_holiday_work' => true,
                        'holiday_name' => $savedHoliday->name,
                        'updated_at' => now(),
                    ]);
            }
        }

        return $rows->count();
    }
}
