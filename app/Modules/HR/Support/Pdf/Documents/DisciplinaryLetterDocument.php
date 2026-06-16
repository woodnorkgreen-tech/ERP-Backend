<?php

namespace App\Modules\HR\Support\Pdf\Documents;

use App\Modules\HR\Models\DisciplinaryCase;
use App\Modules\HR\Support\Pdf\HrPdfDocument;

/**
 * Disciplinary correspondence (PDF) — show-cause notice, warning letter, and
 * outcome letter. These are the legally significant documents in a fair
 * disciplinary process under the Employment Act and must be issued on
 * letterhead and retained on the employee's file.
 */
class DisciplinaryLetterDocument extends HrPdfDocument
{
    public const TYPE_SHOW_CAUSE = 'show_cause';
    public const TYPE_WARNING    = 'warning';
    public const TYPE_OUTCOME    = 'outcome';

    public const TYPES = [self::TYPE_SHOW_CAUSE, self::TYPE_WARNING, self::TYPE_OUTCOME];

    public function __construct(private DisciplinaryCase $case, private string $type)
    {
    }

    protected function view(): string
    {
        return 'pdf.hr.disciplinary_letter';
    }

    protected function filename(): string
    {
        return 'disciplinary-' . str_replace('_', '-', $this->type)
            . '-case-' . $this->case->id . '-' . now()->format('Ymd');
    }

    protected function data(): array
    {
        $case = $this->case->loadMissing('employee.department');

        return [
            'case'      => $case,
            'employee'  => $case->employee,
            'type'      => $this->type,
            'heading'   => $this->heading(),
            'intro'     => $this->intro(),
            'bodyText'  => $this->bodyText($case),
            'issuedOn'  => now(),
        ];
    }

    private function heading(): string
    {
        return match ($this->type) {
            self::TYPE_SHOW_CAUSE => 'Notice to Show Cause',
            self::TYPE_WARNING    => 'Disciplinary Warning',
            self::TYPE_OUTCOME    => 'Outcome of Disciplinary Proceedings',
            default               => 'Disciplinary Notice',
        };
    }

    private function intro(): string
    {
        return match ($this->type) {
            self::TYPE_SHOW_CAUSE => 'You are required to show cause, in writing, why disciplinary '
                . 'action should not be taken against you in respect of the matter set out below.',
            self::TYPE_WARNING => 'Following a review of the matter set out below, the Company hereby '
                . 'issues you with a formal disciplinary warning.',
            self::TYPE_OUTCOME => 'This letter communicates the outcome of the disciplinary '
                . 'proceedings concerning the matter set out below.',
            default => '',
        };
    }

    private function bodyText(DisciplinaryCase $case): ?string
    {
        return match ($this->type) {
            self::TYPE_SHOW_CAUSE => $case->show_cause_letter,
            self::TYPE_WARNING    => $case->warning_letter,
            self::TYPE_OUTCOME    => $case->final_decision ?? $case->hearing_decision,
            default               => null,
        };
    }
}
