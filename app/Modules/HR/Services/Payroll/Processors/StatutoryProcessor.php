<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;

class StatutoryProcessor implements PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void
    {
        $vars = $dto->variables;
        $getVar = function($name, $fallback = 0) use ($vars) {
            return isset($vars[$name]) ? (float)$vars[$name] : $fallback;
        };

        $grossPay = $dto->grossPay;

        // 1. NSSF (Tiered)
        $nssfRate = $getVar('NSSF_RATE', 0.06);
        $nssfTierILimit = $getVar('NSSF_TIER_I_LIMIT', 9000);
        $nssfTierIILimit = $getVar('NSSF_TIER_II_LIMIT', 108000);
        
        $nssfTierI = $nssfRate * min($grossPay, $nssfTierILimit);
        $nssfTierII = $nssfRate * max(0, min($grossPay, $nssfTierIILimit) - $nssfTierILimit);
        $nssf = $nssfTierI + $nssfTierII;

        // 2. SHIF (2.75%, Min 300)
        $shifRate = $getVar('SHIF_RATE', 0.0275);
        $shifMin = $getVar('SHIF_MIN', 300);
        $shif = max($shifMin, $grossPay * $shifRate);

        // 3. Housing Levy (1.5%)
        $housingLevyRate = $getVar('HOUSING_LEVY_RATE', 0.015);
        $housingLevy = $grossPay * $housingLevyRate;

        // 4. PAYE Calculation
        $personalRelief   = $getVar('PERSONAL_RELIEF', 2400);
        $insReliefRate    = $getVar('INSURANCE_RELIEF_RATE', 0.15);
        $insReliefCap     = $getVar('INSURANCE_RELIEF_CAP', 5000);

        // Taxable Pay = Gross - (NSSF + SHIF + Housing Levy)
        $taxablePay = max(0, $grossPay - $nssf - $shif - $housingLevy);
        $calculatedPaye = 0;

        foreach ($dto->taxBands as $band) {
            $min = (float)$band['min_amount'];
            $max = $band['max_amount'] ? (float)$band['max_amount'] : null;
            $rate = (float)$band['rate'];

            if ($taxablePay > $min) {
                $taxableInBand = $max ? min($taxablePay - $min, $max - $min) : $taxablePay - $min;
                $calculatedPaye += $taxableInBand * $rate;
            }
        }

        // Insurance Relief (15% of premiums paid, capped at KES 5,000/month)
        // Premium amount is read from employee ledger if tagged as 'insurance_premium'
        // Fallback: use a configurable INSURANCE_PREMIUM_AMOUNT variable
        $premiumPaid = $getVar('INSURANCE_PREMIUM_AMOUNT', 0);
        $insuranceRelief = min($premiumPaid * $insReliefRate, $insReliefCap);

        // Final PAYE = Band Tax - Personal Relief - Insurance Relief
        $finalPaye = max(0, $calculatedPaye - $personalRelief - $insuranceRelief);

        // Employer-side costs (not deducted from employee but tracked for budgeting)
        $employerNssf = $nssf; // Employer matches employee NSSF contribution
        $employerShif = 0;    // Employer SHIF is separate; can be added as variable if needed

        $dto->taxBreakdown = [
            'paye'               => round($finalPaye, 2),
            'nssf'               => round($nssf, 2),
            'shif'               => round($shif, 2),
            'housing_levy'       => round($housingLevy, 2),
            'personal_relief'    => round($personalRelief, 2),
            'insurance_relief'   => round($insuranceRelief, 2),
            // Employer costs — for financial reporting/budgeting
            'employer_nssf'      => round($employerNssf, 2),
        ];

        // Add employee-side deductions only
        $dto->totalDeductions += ($finalPaye + $nssf + $shif + $housingLevy);
    }
}
