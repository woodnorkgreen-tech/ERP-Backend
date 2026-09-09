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

        // Per-employee statutory exemptions. An exempt deduction is computed as 0
        // so it falls out of the employee's net pay and of the PAYE taxable base.
        $exemptions = $dto->statutoryExemptions ?? [];
        $isExempt = fn (string $code) => in_array($code, $exemptions, true);

        // 1. NSSF (Tiered)
        $nssfRate = $getVar('NSSF_RATE', 0.06);
        $nssfTierILimit = $getVar('NSSF_TIER_I_LIMIT', 9000);
        $nssfTierIILimit = $getVar('NSSF_TIER_II_LIMIT', 108000);

        $nssfTierI = $nssfRate * min($grossPay, $nssfTierILimit);
        $nssfTierII = $nssfRate * max(0, min($grossPay, $nssfTierIILimit) - $nssfTierILimit);
        $nssf = $isExempt('nssf') ? 0.0 : ($nssfTierI + $nssfTierII);

        // 2. SHIF (2.75%, Min KES 300) — compute first, then apply floor
        $shifRate = $getVar('SHIF_RATE', 0.0275);
        $shifMin  = $getVar('SHIF_MIN', 300);
        $shif     = $grossPay * $shifRate;
        if ($shif < $shifMin) $shif = $shifMin;
        if ($isExempt('shif')) $shif = 0.0;

        // 3. Housing Levy (1.5%)
        $housingLevyRate = $getVar('HOUSING_LEVY_RATE', 0.015);
        $housingLevy = $isExempt('housing_levy') ? 0.0 : ($grossPay * $housingLevyRate);

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
        // Read actual premium from ledger deductions tagged 'insurance_premium'; fall back to variable
        $premiumPaid = collect($dto->ledgerDetails)
            ->where('type', 'deduction')
            ->filter(fn($l) => stripos($l['name'], 'insurance_premium') !== false)
            ->sum('amount');
        if ($premiumPaid == 0) {
            $premiumPaid = $getVar('INSURANCE_PREMIUM_AMOUNT', 0);
        }
        $insuranceRelief = min($premiumPaid * $insReliefRate, $insReliefCap);

        // Final PAYE = Band Tax - Personal Relief - Insurance Relief
        $finalPaye = $isExempt('paye') ? 0.0 : max(0, $calculatedPaye - $personalRelief - $insuranceRelief);
        if ($isExempt('paye')) {
            $calculatedPaye = 0.0;
            $insuranceRelief = 0.0;
        }

        /*
         * Employer-side costs.
         *
         * These are NOT deducted from the employee — they are what WNG pays on
         * top of the salary. Until Stage 2 of the general ledger plan they were
         * computed here and recorded nowhere in the accounts, so the true cost
         * of employing someone was understated by every shilling below.
         *
         * The rates are variables with defaults, following the same pattern as
         * the employee side, so a statutory change is a configuration change
         * rather than a deploy. THE DEFAULTS NEED CONFIRMING WITH WNG'S TAX
         * ADVISER — they mirror the employee-side rates already in this file,
         * which is the common arrangement but not a substitute for advice.
         */
        $employerNssf = $isExempt('nssf')
            ? 0.0
            : $nssf * $getVar('EMPLOYER_NSSF_MATCH', 1.0); // employer matches the employee tiers

        // The Affordable Housing Levy is paid by both sides. Only the employee
        // half was ever computed, so the employer half reached neither the
        // payslip nor the ledger.
        $employerHousingLevy = $isExempt('housing_levy')
            ? 0.0
            : $grossPay * $getVar('EMPLOYER_HOUSING_LEVY_RATE', $housingLevyRate);

        // SHIF is an employee contribution; there is no employer match to
        // compute. Left explicit so its absence reads as a decision.
        $employerShif = $grossPay * $getVar('EMPLOYER_SHIF_RATE', 0.0);

        $dto->taxBreakdown = [
            'paye'               => round($finalPaye, 2),
            'calculated_paye'    => round($calculatedPaye, 2), // raw band tax before reliefs — P9 column I "Tax Charged"
            'nssf'               => round($nssf, 2),
            'shif'               => round($shif, 2),
            'housing_levy'       => round($housingLevy, 2),
            'personal_relief'    => round($personalRelief, 2),
            'insurance_relief'   => round($insuranceRelief, 2),
            'employer_nssf'      => round($employerNssf, 2),
            'employer_housing_levy' => round($employerHousingLevy, 2),
            'employer_shif'      => round($employerShif, 2),
            // What employing this person costs WNG beyond their gross pay. Read
            // by PayrollFinancePostingService as one figure so a new employer
            // charge is added here and reaches the ledger without touching it.
            'employer_total'     => round($employerNssf + $employerHousingLevy + $employerShif, 2),
            'exemptions'         => array_values($exemptions), // statutory items skipped for this employee
        ];

        // Add employee-side deductions only
        $dto->totalDeductions += ($finalPaye + $nssf + $shif + $housingLevy);
    }
}
