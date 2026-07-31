<?php

namespace App\Modules\HR\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LeaveRegisterSummarySheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithStyles
{
    public function __construct(private readonly Collection $entries)
    {
    }

    public function array(): array
    {
        return $this->entries
            ->map(fn (array $entry) => [
                $entry['employee']['name'],
                $entry['employee']['employee_id'],
                $entry['employee']['department'],
                $entry['total_allocated_days'],
                $entry['total_used_days'],
                $entry['total_remaining_days'],
            ])
            ->all();
    }

    public function headings(): array
    {
        return ['Staff Name', 'Staff No.', 'Department', 'Annual Leave Allocated', 'Total Days Taken (All Leave Types)', 'Annual Leave Remaining'];
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function styles(Worksheet $sheet)
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
