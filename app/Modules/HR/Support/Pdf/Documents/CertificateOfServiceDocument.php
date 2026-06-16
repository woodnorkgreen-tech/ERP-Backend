<?php

namespace App\Modules\HR\Support\Pdf\Documents;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\Pdf\HrPdfDocument;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Certificate of Service — mandatory under the Employment Act (Kenya) s.51,
 * which an employer must issue on termination of employment.
 *
 * Per s.51(2) the certificate states only the employee's identity, the period
 * of employment and the nature of the work — it must NOT comment on conduct or
 * the reason for leaving unless the employee requests it.
 */
class CertificateOfServiceDocument extends HrPdfDocument
{
    public function __construct(private Employee $employee)
    {
    }

    protected function view(): string
    {
        return 'pdf.hr.certificate_of_service';
    }

    protected function filename(): string
    {
        $slug = Str::slug(trim($this->employee->first_name . ' ' . $this->employee->last_name));

        return 'certificate-of-service-' . ($slug ?: $this->employee->id) . '-' . now()->format('Ymd');
    }

    protected function data(): array
    {
        $employee = $this->employee->loadMissing('department');

        $start = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;

        // End of service: the soft-delete timestamp set at termination, else
        // today for an employee flagged terminated, else null (still serving).
        $end = $employee->deleted_at
            ? Carbon::parse($employee->deleted_at)
            : ($employee->status === 'terminated' ? now() : null);

        $period = $start ? $this->describePeriod($start, $end ?? now()) : null;

        return [
            'employee'   => $employee,
            'fullName'   => trim($employee->first_name . ' ' . $employee->last_name),
            'position'   => $employee->position,
            'department' => $employee->department?->name,
            'idNumber'   => $employee->id_number,
            'startDate'  => $start,
            'endDate'    => $end,
            'period'     => $period,
            'stillServing' => $end === null,
            'issuedOn'   => now(),
        ];
    }

    private function describePeriod(Carbon $start, Carbon $end): string
    {
        $diff = $start->diff($end);
        $parts = [];

        if ($diff->y) {
            $parts[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
        }
        if ($diff->m) {
            $parts[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
        }
        if (! $parts) {
            $parts[] = max(1, $diff->d) . ' day' . ($diff->d > 1 ? 's' : '');
        }

        return implode(' and ', $parts);
    }
}
