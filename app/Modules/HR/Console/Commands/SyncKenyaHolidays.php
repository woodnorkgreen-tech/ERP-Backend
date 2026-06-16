<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Services\KenyaHolidayImportService;
use Illuminate\Console\Command;

class SyncKenyaHolidays extends Command
{
    protected $signature = 'attendance:sync-kenya-holidays {--year=* : Year(s) to import}';

    protected $description = 'Download and update Kenyan public holidays';

    public function handle(KenyaHolidayImportService $service): int
    {
        $years = collect($this->option('year'))->filter()->map(fn ($year) => (int) $year);
        if ($years->isEmpty()) {
            $years = collect(range(
                now()->year - max(0, (int) config('attendance.holiday_import_years_back', 1)),
                now()->year + max(0, (int) config('attendance.holiday_import_years_ahead', 1))
            ));
        }

        foreach ($years->unique() as $year) {
            if ($year < 2000 || $year > 2100) {
                $this->error("Invalid holiday year: {$year}");
                return self::FAILURE;
            }
            $count = $service->importYear($year);
            $this->info("Imported {$count} Kenyan holiday(s) for {$year}.");
        }

        return self::SUCCESS;
    }
}
