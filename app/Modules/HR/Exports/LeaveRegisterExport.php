<?php

namespace App\Modules\HR\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LeaveRegisterExport implements WithMultipleSheets
{
    public function __construct(private readonly Collection $entries)
    {
    }

    public function sheets(): array
    {
        return [
            'Summary' => new LeaveRegisterSummarySheet($this->entries),
            'Breakdown' => new LeaveRegisterBreakdownSheet($this->entries),
        ];
    }
}
