<?php

namespace App\Modules\HR\Support\Pdf\Documents;

use App\Modules\HR\Support\Pdf\HrPdfDocument;
use Illuminate\Support\Str;

/**
 * KRA P9A annual tax deduction card (PDF). Replaces the CSV as the employee-
 * facing tax certificate. Built from data produced by P9CardService.
 */
class P9CardDocument extends HrPdfDocument
{
    protected array $paper = ['a4', 'landscape'];

    /**
     * @param array{employee: \App\Modules\HR\Models\Employee, year: string, rows: array, totals: array} $p9
     */
    public function __construct(private array $p9)
    {
    }

    protected function view(): string
    {
        return 'pdf.hr.p9_card';
    }

    protected function filename(): string
    {
        $employee = $this->p9['employee'];
        $slug = Str::slug(trim($employee->first_name . ' ' . $employee->last_name));

        return 'P9A-' . ($slug ?: $employee->id) . '-' . $this->p9['year'];
    }

    protected function data(): array
    {
        return $this->p9;
    }
}
