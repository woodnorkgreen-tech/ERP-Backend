<?php

namespace App\Modules\HR\Support\Pdf\Documents;

use App\Modules\HR\Support\Pdf\HrPdfDocument;
use Illuminate\Support\Collection;

/**
 * The salary-advance register as a signed-off PDF for Finance: totals, the
 * advances awaiting payout with their payment details, and the full register
 * for the filters HR applied. Rows come from SalaryAdvanceRegister::rows(),
 * the same source as the Excel, so both always agree.
 */
class SalaryAdvanceRegisterDocument extends HrPdfDocument
{
    protected array $paper = ['a4', 'landscape'];

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $filterLines
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $filterLines,
        private readonly string $generatedBy,
    ) {}

    protected function view(): string
    {
        return 'pdf.hr.salary_advance_register';
    }

    protected function filename(): string
    {
        return 'salary-advances_'.now()->format('Y-m-d_Hi');
    }

    public function data(): array
    {
        $due = $this->rows->where('stage_key', 'approved')->values();
        $paid = $this->rows->whereIn('stage_key', ['outstanding', 'recovered']);

        return [
            'rows' => $this->rows->values(),
            'due' => $due,
            'filterLines' => $this->filterLines,
            'generatedBy' => $this->generatedBy,
            'generatedAt' => now()->format('d M Y, H:i'),
            'totals' => [
                'count' => $this->rows->count(),
                'amount' => $this->rows->sum('amount'),
                'due_count' => $due->count(),
                'due_amount' => $due->sum('amount'),
                'due_missing' => $due->filter(fn ($row) => $row['missing_payment_details'])->count(),
                'due_self_approved' => $due->filter(fn ($row) => $row['self_approved'])->count(),
                'paid_amount' => $paid->sum('amount'),
                'recovered' => $this->rows->sum('recovered'),
                'outstanding' => $this->rows->sum(fn ($row) => (float) ($row['outstanding'] ?? 0)),
            ],
        ];
    }
}
