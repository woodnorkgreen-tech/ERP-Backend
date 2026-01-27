<?php

namespace App\Modules\Finance\PettyCash\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PettyCashTransactionsExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithStyles
{
    protected $headings;
    protected $data;
    protected $title;

    public function __construct(array $headings, array $data, string $title = 'Transactions')
    {
        $this->headings = $headings;
        $this->data = $data;
        $this->title = ucfirst($title);
    }

    public function array(): array
    {
        return $this->data;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text.
            1 => ['font' => ['bold' => true]],
        ];
    }
}
