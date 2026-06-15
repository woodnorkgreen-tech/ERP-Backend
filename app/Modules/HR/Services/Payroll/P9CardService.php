<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Payslip;

/**
 * Builds the data for a KRA P9A annual tax card from an employee's payslips.
 *
 * Extracted so the figures are computed in one place and consumed identically
 * by the CSV export and the P9 PDF document (single source of truth for the
 * P9 columns A, B, D, E2, H, I, J and L).
 */
class P9CardService
{
    /**
     * @return array{employee: Employee, year: string, rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function build(int $employeeId, string $year): array
    {
        $employee = Employee::findOrFail($employeeId);

        $payslips = Payslip::where('employee_id', $employeeId)
            ->where('payroll_month', 'like', $year . '-%')
            ->orderBy('payroll_month')
            ->get();

        $rows = [];
        $totals = array_fill_keys(
            ['basic', 'benefits', 'gross', 'nssf', 'taxable', 'tax_charged', 'relief', 'paye'],
            0.0
        );

        foreach ($payslips as $slip) {
            $tax = $slip->tax_breakdown ?? [];

            $basic    = (float) $slip->basic_salary;
            $gross    = (float) $slip->gross_pay;
            $benefits = $gross - $basic;
            $nssf     = (float) ($tax['nssf'] ?? 0);
            // P9 Taxable Pay (H) is Gross less the pension/NSSF contribution.
            $taxable  = $gross - $nssf;
            $paye     = (float) ($tax['paye'] ?? 0);
            $relief   = (float) ($tax['personal_relief'] ?? 0) + (float) ($tax['insurance_relief'] ?? 0);
            // Persisted raw band tax (Tax Charged, col I); reconstruct for legacy payslips.
            $taxCharged = (float) ($tax['calculated_paye'] ?? ($paye + $relief));

            $rows[] = [
                'month'       => date('F', strtotime($slip->payroll_month . '-01')),
                'basic'       => $basic,
                'benefits'    => $benefits,
                'gross'       => $gross,
                'nssf'        => $nssf,
                'taxable'     => $taxable,
                'tax_charged' => $taxCharged,
                'relief'      => $relief,
                'paye'        => $paye,
            ];

            $totals['basic']       += $basic;
            $totals['benefits']    += $benefits;
            $totals['gross']       += $gross;
            $totals['nssf']        += $nssf;
            $totals['taxable']     += $taxable;
            $totals['tax_charged'] += $taxCharged;
            $totals['relief']      += $relief;
            $totals['paye']        += $paye;
        }

        return [
            'employee' => $employee,
            'year'     => $year,
            'rows'     => $rows,
            'totals'   => $totals,
        ];
    }
}
