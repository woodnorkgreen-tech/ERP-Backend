<?php

namespace App\Modules\HR\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LeaveRegisterBreakdownSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithStyles
{
    public function __construct(private readonly Collection $entries)
    {
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->entries as $entry) {
            foreach ($entry['instances'] as $instance) {
                $rows[] = [
                    $entry['employee']['name'],
                    $entry['employee']['employee_id'],
                    $instance['leave_type_name'],
                    $instance['start_date'],
                    $instance['end_date'],
                    $instance['days_requested'],
                    $instance['reason'],
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Staff Name', 'Staff No.', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Reason'];
    }

    public function title(): string
    {
        return 'Breakdown';
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
