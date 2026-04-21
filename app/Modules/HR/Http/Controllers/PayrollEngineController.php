<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\HR\Models\PayrollVariable;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\PayrollTaxBand;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollEngineController extends Controller
{
    // --- VARIABLES (SETTINGS) ---
    public function getVariables()
    {
        return response()->json(PayrollVariable::orderBy('name')->get());
    }

    public function storeVariable(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'value' => 'required|numeric',
            'description' => 'nullable|string'
        ]);

        $variable = PayrollVariable::updateOrCreate(
            ['name' => $validated['name']],
            [
                'type' => $validated['type'],
                'value' => $validated['value'],
                'description' => $validated['description'] ?? null,
                'is_active' => true
            ]
        );

        return response()->json($variable, 201);
    }

    public function toggleVariable($id)
    {
        $variable = PayrollVariable::findOrFail($id);
        $variable->is_active = !$variable->is_active;
        $variable->save();
        return response()->json($variable);
    }

    // --- LEDGERS ---
    public function getLedgers(Request $request)
    {
        $query = PayrollLedger::with('employee');
        
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        
        if ($request->has('ledger_month')) {
            $query->where('ledger_month', $request->ledger_month);
        }

        return response()->json($query->orderByDesc('created_at')->get());
    }

    public function storeLedger(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'ledger_month' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'type' => 'required|in:addition,deduction',
            'amount_type' => 'required|in:fixed,percentage_of_basic',
            'amount_value' => 'required|numeric|min:0',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_recurring' => 'boolean'
        ]);

        $ledger = PayrollLedger::create($validated);
        $ledger->load('employee');

        return response()->json($ledger, 201);
    }

    public function updateLedger(Request $request, $id)
    {
        $ledger = PayrollLedger::findOrFail($id);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'ledger_month' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'type' => 'required|in:addition,deduction',
            'amount_type' => 'required|in:fixed,percentage_of_basic',
            'amount_value' => 'required|numeric|min:0',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_recurring' => 'boolean'
        ]);

        $ledger->update($validated);
        $ledger->load('employee');

        return response()->json($ledger);
    }

    public function destroyLedger($id)
    {
        $ledger = PayrollLedger::findOrFail($id);
        $ledger->delete();
        return response()->json(['message' => 'Ledger entry removed']);
    }

    public function exportLedgerTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="Ledger_Import_Template.csv"',
        ];

        $callback = function() {
            $file = fopen('php://output', 'w');
            // Provide the headers
            fputcsv($file, [
                'Employee_ID', 
                'Month(YYYY-MM)', 
                'Type(addition/deduction)', 
                'Amount_Type(fixed/percentage_of_basic)', 
                'Amount', 
                'Name', 
                'Description', 
                'Recurring(yes/no)'
            ]);
            
            // Provide a sample row
            fputcsv($file, [
                'EMP001', 
                date('Y-m'), 
                'addition', 
                'fixed', 
                '5000', 
                'Performance Bonus', 
                'Q1 Performance', 
                'no'
            ]);
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importLedgers(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048'
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle, 1000, ',');

        $results = [
            'total' => 0,
            'processed' => 0,
            'errors' => []
        ];

        \DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                // If the row is empty or not matching header length, skip
                if (count($row) < 8 || empty(trim($row[0]))) {
                    continue;
                }

                $results['total']++;
                $empIdRaw = trim($row[0]);
                $month = trim($row[1]);
                $type = strtolower(trim($row[2]));
                $amountType = strtolower(trim($row[3]));
                $amount = trim($row[4]);
                $name = trim($row[5]);
                $description = trim($row[6]);
                $recurring = strtolower(trim($row[7])) === 'yes' || strtolower(trim($row[7])) === 'true' || trim($row[7]) === '1' ? true : false;

                $employee = Employee::where('employee_id', $empIdRaw)->first();

                if (!$employee) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Employee ID {$empIdRaw} not found"];
                    continue;
                }

                // Basic validation
                if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Invalid month format {$month} for {$empIdRaw}"];
                    continue;
                }

                if (!in_array($type, ['addition', 'deduction'])) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Invalid type {$type} for {$empIdRaw}"];
                    continue;
                }

                if (!in_array($amountType, ['fixed', 'percentage_of_basic'])) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Invalid amount_type {$amountType} for {$empIdRaw}"];
                    continue;
                }

                if (!is_numeric($amount) || $amount < 0) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Invalid amount {$amount} for {$empIdRaw}"];
                    continue;
                }

                if (empty($name)) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Name is required for {$empIdRaw}"];
                    continue;
                }

                PayrollLedger::create([
                    'employee_id' => $employee->id,
                    'ledger_month' => $month,
                    'type' => $type,
                    'amount_type' => $amountType,
                    'amount_value' => (float)$amount,
                    'name' => $name,
                    'description' => $description,
                    'is_recurring' => $recurring
                ]);

                $results['processed']++;
            }
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['message' => 'Failed to process import file: ' . $e->getMessage()], 422);
        } finally {
            fclose($handle);
        }

        return response()->json($results);
    }


    // --- PAYSLIPS ---
    public function getPayslips(Request $request)
    {
        $query = Payslip::with('employee');
        
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        
        if ($request->has('payroll_month')) {
            $query->where('payroll_month', $request->payroll_month);
        }

        return response()->json($query->orderByDesc('payroll_month')->get());
    }

    public function generatePayslip(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        try {
            $payslip = $this->calculateMonthlyFull($validated['employee_id'], $validated['payroll_month']);
            return response()->json($payslip);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function batchGenerate(Request $request)
    {
        $validated = $request->validate([
            'payroll_month'  => 'required|string|regex:/^\d{4}-\d{2}$/',
            'department_id'  => 'nullable|exists:departments,id',
            'employee_ids'   => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $month = $validated['payroll_month'];
        $query = Employee::where('status', 'active');

        // Specific employee selection takes priority over department filter
        if (!empty($validated['employee_ids'])) {
            $query->whereIn('id', $validated['employee_ids']);
        } elseif (!empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        $employees = $query->get();
        $results = [
            'total'     => $employees->count(),
            'processed' => 0,
            'errors'    => []
        ];

        foreach ($employees as $employee) {
            try {
                $this->calculateMonthlyFull($employee->id, $month);
                $results['processed']++;
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'employee' => $employee->full_name,
                    'message'  => $e->getMessage()
                ];
            }
        }

        return response()->json($results);
    }

    private function calculateMonthlyFull($employeeId, $month)
    {
        $employee = Employee::findOrFail($employeeId);
        $basicSalary = $employee->salary ?? 0;

        // 1. Fetch Ledgers
        $ledgers = PayrollLedger::where('employee_id', $employee->id)
            ->where(function($q) use ($month) {
                $q->where('ledger_month', $month)
                  ->orWhere('is_recurring', true);
            })->get();

        $additions = 0;
        $customDeductions = 0;
        $ledgerDetails = [];

        foreach ($ledgers as $ledger) {
            $amount = 0;
            if ($ledger->amount_type === 'percentage_of_basic') {
                $amount = ($ledger->amount_value / 100) * $basicSalary;
            } else {
                $amount = $ledger->amount_value;
            }

            if ($ledger->type === 'addition') {
                $additions += $amount;
            } else {
                $customDeductions += $amount;
            }

            $ledgerDetails[] = [
                'name' => $ledger->name,
                'type' => $ledger->type,
                'amount' => $amount
            ];
        }

        $grossPay = $basicSalary + $additions;

        // 2. Statutory Vars (March 2026 Kenyan Requirements)
        $allVars = PayrollVariable::all()->keyBy('name');
        $getVar = function($name, $fallback = 0) use ($allVars) {
            if (!isset($allVars[$name])) return $fallback;
            return $allVars[$name]->is_active ? (float)$allVars[$name]->value : 0;
        };
        
        $nssfRate = $getVar('NSSF_RATE', 0.06);
        $nssfTierILimit = $getVar('NSSF_TIER_I_LIMIT', 9000);
        $nssfTierIILimit = $getVar('NSSF_TIER_II_LIMIT', 108000);
        
        $shifRate = $getVar('SHIF_RATE', 0.0275);
        $shifMin = $getVar('SHIF_MIN', 300);
        
        $housingLevyRate = $getVar('HOUSING_LEVY_RATE', 0.015);
        $personalRelief = $getVar('PERSONAL_RELIEF', 2400);
        
        $insReliefRate = $getVar('INSURANCE_RELIEF_RATE', 0.15);
        $insReliefCap = $getVar('INSURANCE_RELIEF_CAP', 5000);

        // --- STATUTORY DEDUCTIONS ---
        
        // Tiered NSSF Calculation
        $nssfTierI = $nssfRate * min($grossPay, $nssfTierILimit);
        $nssfTierII = $nssfRate * max(0, min($grossPay, $nssfTierIILimit) - $nssfTierILimit);
        $nssf = $nssfTierI + $nssfTierII;

        // SHIF (Minimum Ksh 300, No Cap)
        $shif = max($shifMin, $grossPay * $shifRate);

        // Housing Levy (1.5%)
        $housingLevy = $grossPay * $housingLevyRate;

        // --- PAYE CALCULATION ---
        
        // Tax-Deductible items (NSSF, SHIF, and Housing Levy are now deductible before PAYE)
        $taxablePay = $grossPay - $nssf - $shif - $housingLevy;
        $calculatedPaye = 0;
        $bands = PayrollTaxBand::where('is_active', true)->orderBy('sort_order')->get();

        if ($bands->isEmpty()) {
            throw new \Exception("No PAYE Tax Bands found. Please configure in Settings first.");
        }

        foreach ($bands as $band) {
            $min = $band->min_amount;
            $max = $band->max_amount;
            $rate = $band->rate;

            if ($taxablePay > $min) {
                $taxableInBand = $max ? min($taxablePay - $min, $max - $min) : $taxablePay - $min;
                $calculatedPaye += $taxableInBand * $rate;
            }
        }

        // --- RELIEFS ---
        
        // Final PAYE = Total Band Tax - Personal Relief
        $paye = max(0, $calculatedPaye - $personalRelief);
        
        $totalStatutory = $nssf + $shif + $housingLevy + $paye;
        $netPay = $grossPay - $totalStatutory - $customDeductions;

        return Payslip::updateOrCreate(
            ['employee_id' => $employee->id, 'payroll_month' => $month],
            [
                'basic_salary' => $basicSalary,
                'gross_pay' => $grossPay,
                'net_pay' => $netPay,
                'tax_breakdown' => [
                    'paye' => $paye, 'nssf' => $nssf, 'shif' => $shif,
                    'housing_levy' => $housingLevy, 'personal_relief' => $personalRelief
                ],
                'ledger_breakdown' => $ledgerDetails,
                'status' => 'draft',
                'updated_at' => now()
            ]
        );
    }

    public function generatePdf($id)
    {
        $payslip = Payslip::with(['employee', 'employee.department'])->findOrFail($id);

        $pdf = Pdf::loadView('hr.payslip', [
            'payslip' => $payslip
        ]);

        $fileName = 'Payslip_' . str_replace(' ', '_', $payslip->employee->name) . '_' . $payslip->payroll_month . '.pdf';
        
        return $pdf->download($fileName);
    }

    public function destroyPayslip($id)
    {
        $payslip = Payslip::findOrFail($id);
        $payslip->delete();
        return response()->json(['message' => 'Payslip record removed']);
    }

    public function clearPayslips(Request $request)
    {
        $validated = $request->validate([
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        $query = Payslip::where('payroll_month', $validated['payroll_month']);
        
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $count = $query->count();
        $query->delete();

        return response()->json(['message' => "Cleared $count records for " . $validated['payroll_month']]);
    }

    // --- TAX BANDS MANAGEMENT ---
    public function getTaxBands()
    {
        return response()->json(PayrollTaxBand::orderBy('sort_order')->get());
    }

    public function storeTaxBand(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'rate' => 'required|numeric|min:0|max:1',
            'sort_order' => 'integer'
        ]);

        $band = PayrollTaxBand::updateOrCreate(
            ['name' => $validated['name']],
            [
                'min_amount' => $validated['min_amount'],
                'max_amount' => $validated['max_amount'],
                'rate' => $validated['rate'],
                'sort_order' => $validated['sort_order'] ?? 0,
                'is_active' => true
            ]
        );

        return response()->json($band, 201);
    }

    public function toggleTaxBand($id)
    {
        $band = PayrollTaxBand::findOrFail($id);
        $band->is_active = !$band->is_active;
        $band->save();
        return response()->json($band);
    }

    public function exportBankRemittance(Request $request)
    {
        $validated = $request->validate([
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        $payslips = Payslip::with('employee')
            ->where('payroll_month', $validated['payroll_month'])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="Bank_Remittance_' . $validated['payroll_month'] . '.csv"',
        ];

        $callback = function() use ($payslips, $validated) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Beneficiary Bank Code', 'Beneficiary Name', 'Beneficiary Account', 'Amount', 'Currency', 'Remarks']);

            foreach ($payslips as $slip) {
                fputcsv($file, [
                    $slip->employee->bank_code ?? '',
                    $slip->employee->first_name . ' ' . $slip->employee->last_name,
                    $slip->employee->account_number,
                    $slip->net_pay,
                    'KES',
                    'Payroll - ' . $validated['payroll_month']
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportMpesaRemittance(Request $request)
    {
        $validated = $request->validate([
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        $payslips = Payslip::with('employee')
            ->where('payroll_month', $validated['payroll_month'])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="MPESA_Remittance_' . $validated['payroll_month'] . '.csv"',
        ];

        $callback = function() use ($payslips) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['To (Phone number)', 'Amount', 'Remarks', 'Beneficiary (Name)']);

            foreach ($payslips as $slip) {
                fputcsv($file, [
                    $slip->employee->phone ?? '',
                    $slip->net_pay,
                    'Salary Payment',
                    $slip->employee->first_name . ' ' . $slip->employee->last_name
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportP9(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'required|integer|min:2020|max:2030'
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);
        $payslips = Payslip::where('employee_id', $validated['employee_id'])
            ->where('payroll_month', 'like', $validated['year'] . '-%')
            ->orderBy('payroll_month')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="P9_' . $employee->first_name . '_' . $validated['year'] . '.csv"',
        ];

        $callback = function() use ($payslips, $employee, $validated) {
            $file = fopen('php://output', 'w');
            
            // P9 Header Info
            fputcsv($file, ['P9 CARD - ANNUAL TAX RETURN', 'YEAR:', $validated['year']]);
            fputcsv($file, ['Employee Name:', $employee->first_name . ' ' . $employee->last_name]);
            fputcsv($file, ['Employee PIN:', $employee->kra_pin ?? 'N/A']);
            fputcsv($file, ['Employer:', 'WNG ERP SOLUTIONS']);
            fputcsv($file, []); // Empty line

            // Column Headers
            fputcsv($file, [
                'Month', 
                'Basic Salary (A)', 
                'Benefits (B)', 
                'Quarters (C)', 
                'Total Gross (D)', 
                'NSSF (E2)', 
                'Taxable Pay (H)', 
                'Tax Charged (I)', 
                'Relief (J)', 
                'PAYE (L)'
            ]);

            $totals = [
                'basic' => 0, 'benefits' => 0, 'quarters' => 0, 'gross' => 0, 
                'nssf' => 0, 'taxable' => 0, 'tax_charged' => 0, 'relief' => 0, 'paye' => 0
            ];

            foreach ($payslips as $slip) {
                $tax = $slip->tax_breakdown;
                $basic = (float)$slip->basic_salary;
                $benefits = (float)$slip->gross_pay - $basic;
                $gross = (float)$slip->gross_pay;
                $nssf = (float)($tax['nssf'] ?? 0);
                
                // On P9, Taxable Pay (H) is Gross - Pension (Standard). 
                // Note: SHIF/Levy are deductible in calculation but P9 layout typically shows Pension/NSSF specifically.
                $taxable = $gross - $nssf; 
                
                $finalPaye = (float)($tax['paye'] ?? 0);
                $relief = (float)($tax['personal_relief'] ?? 0);
                $taxCharged = $finalPaye + $relief; // Reconstruct tax before relief

                $monthName = date('F', strtotime($slip->payroll_month . '-01'));

                fputcsv($file, [
                    $monthName,
                    number_format($basic, 2),
                    number_format($benefits, 2),
                    '0.00',
                    number_format($gross, 2),
                    number_format($nssf, 2),
                    number_format($taxable, 2),
                    number_format($taxCharged, 2),
                    number_format($relief, 2),
                    number_format($finalPaye, 2)
                ]);

                // Update totals
                $totals['basic'] += $basic;
                $totals['benefits'] += $benefits;
                $totals['gross'] += $gross;
                $totals['nssf'] += $nssf;
                $totals['taxable'] += $taxable;
                $totals['tax_charged'] += $taxCharged;
                $totals['relief'] += $relief;
                $totals['paye'] += $finalPaye;
            }

            // Totals Row
            fputcsv($file, []);
            fputcsv($file, [
                'TOTALS',
                number_format($totals['basic'], 2),
                number_format($totals['benefits'], 2),
                '0.00',
                number_format($totals['gross'], 2),
                number_format($totals['nssf'], 2),
                number_format($totals['taxable'], 2),
                number_format($totals['tax_charged'], 2),
                number_format($totals['relief'], 2),
                number_format($totals['paye'], 2)
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function getComplianceSummary(Request $request)
    {
        $month = $request->get('payroll_month', date('Y-m'));
        
        $payslips = Payslip::where('payroll_month', $month)->get();
        
        $summary = [
            'total_gross' => 0,
            'total_paye' => 0,
            'total_nssf' => 0,
            'total_shif' => 0,
            'total_housing_levy' => 0,
            'employee_count' => $payslips->count(),
            'paid_count' => $payslips->where('status', 'paid')->count(),
        ];

        foreach ($payslips as $slip) {
            $tax = $slip->tax_breakdown;
            $summary['total_gross'] += (float)$slip->gross_pay;
            $summary['total_paye'] += (float)($tax['paye'] ?? 0);
            $summary['total_nssf'] += (float)($tax['nssf'] ?? 0);
            $summary['total_shif'] += (float)($tax['shif'] ?? 0);
            $summary['total_housing_levy'] += (float)($tax['housing_levy'] ?? 0);
        }

        return response()->json($summary);
    }

    public function markAsPaid(Request $request)
    {
        $validated = $request->validate([
            'payroll_month' => 'required|string',
        ]);

        Payslip::where('payroll_month', $validated['payroll_month'])
            ->update([
                'status' => 'paid',
                'payment_date' => now()
            ]);

        return response()->json(['message' => 'All payslips for ' . $validated['payroll_month'] . ' marked as paid.']);
    }
}
