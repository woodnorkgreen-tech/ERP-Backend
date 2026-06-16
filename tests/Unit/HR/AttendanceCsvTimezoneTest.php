<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Services\AttendanceCsvImportService;
use App\Modules\HR\Services\AttendanceProcessingService;
use Illuminate\Http\UploadedFile;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AttendanceCsvTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hikvision.device_timezone', 'Africa/Nairobi');
        config()->set('hikvision.storage_timezone', 'Africa/Nairobi');
    }

    public function test_plain_csv_timestamp_remains_nairobi_local_time(): void
    {
        $rows = $this->service()->parseFile($this->csvFile([
            '1001,Jane Doe,Operations,2026-06-11 08:00:00,Present,Main Door',
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-11 08:00:00', $rows->first()['event_datetime']);
    }

    public function test_offset_csv_timestamp_converts_to_nairobi_and_crosses_midnight(): void
    {
        $rows = $this->service()->parseFile($this->csvFile([
            '1001,Jane Doe,Operations,2026-06-10T21:30:00Z,Present,Main Door',
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-11 00:30:00', $rows->first()['event_datetime']);
    }

    public function test_malformed_csv_timestamp_is_skipped(): void
    {
        $rows = $this->service()->parseFile($this->csvFile([
            '1001,Jane Doe,Operations,not-a-date,Present,Main Door',
        ]));

        $this->assertCount(0, $rows);
    }

    public function test_excel_timestamp_uses_the_same_timezone_conversion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attendance-') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Person ID', 'Name', 'Department', 'Time', 'Attendance Status', 'Check Point'],
            ['1001', 'Jane Doe', 'Operations', '2026-06-10T21:30:00Z', 'Present', 'Main Door'],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $file = new UploadedFile(
                $path,
                'attendance.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );
            $rows = $this->service()->parseFile($file);

            $this->assertCount(1, $rows);
            $this->assertSame('2026-06-11 00:30:00', $rows->first()['event_datetime']);
        } finally {
            @unlink($path);
        }
    }

    private function service(): AttendanceCsvImportService
    {
        return new AttendanceCsvImportService(
            Mockery::mock(AttendanceProcessingService::class)
        );
    }

    private function csvFile(array $rows): UploadedFile
    {
        $header = 'Person ID,Name,Department,Time,Attendance Status,Check Point';

        return UploadedFile::fake()->createWithContent(
            'attendance.csv',
            implode("\n", [$header, ...$rows])
        );
    }
}
