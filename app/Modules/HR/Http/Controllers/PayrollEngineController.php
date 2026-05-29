<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\HR\Models\PayrollVariable;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\PayrollTaxBand;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Services\Payroll\PayrollService;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class PayrollEngineController extends Controller
{
    protected PayrollService $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }
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

    public function exportLedgerTemplate(Request $request)
    {
        $month = $request->query('month', date('Y-m'));
        $employeeIds = $request->query('employee_ids', []);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="Ledger_Import_Template_' . $month . '.csv"',
        ];

        $callback = function() use ($month, $employeeIds) {
            $file = fopen('php://output', 'w');
            // Provide the headers
            fputcsv($file, [
                'Employee_ID', 
                'Employee_Name',
                'Month(YYYY-MM)', 
                'Type(addition/deduction)', 
                'Amount_Type(fixed/percentage_of_basic)', 
                'Amount', 
                'Name', 
                'Description', 
                'Recurring(yes/no)'
            ]);
            
            if (!empty($employeeIds) && is_array($employeeIds)) {
                $employees = Employee::whereIn('id', $employeeIds)->get();
                foreach ($employees as $emp) {
                    fputcsv($file, [
                        $emp->employee_id, 
                        $emp->first_name . ' ' . $emp->last_name,
                        $month, 
                        'addition', 
                        'fixed', 
                        '', 
                        '', 
                        '', 
                        'no'
                    ]);
                }
            } else {
                // Provide a sample row
                fputcsv($file, [
                    'EMP001', 
                    'John Doe',
                    $month, 
                    'addition', 
                    'fixed', 
                    '5000', 
                    'Performance Bonus', 
                    'Q1 Performance', 
                    'no'
                ]);
            }
            
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
                // Determine format
                $isLegacy = count($row) === 8;
                $isModern = count($row) >= 9;

                if (!$isLegacy && !$isModern) {
                    continue; // invalid row
                }
                
                $empIdRaw = trim($row[0]);
                if (empty($empIdRaw) || strtolower($empIdRaw) === 'employee_id') {
                    continue; // Skip header or empty row
                }
                
                $monthIdx = $isLegacy ? 1 : 2;
                $typeIdx = $isLegacy ? 2 : 3;
                $amountTypeIdx = $isLegacy ? 3 : 4;
                $amountIdx = $isLegacy ? 4 : 5;
                $nameIdx = $isLegacy ? 5 : 6;
                $descriptionIdx = $isLegacy ? 6 : 7;
                $recurringIdx = $isLegacy ? 7 : 8;

                $amount = trim($row[$amountIdx] ?? '');
                $name = trim($row[$nameIdx] ?? '');
                
                // --- TRAP 1: Blank rows from pre-filled template ---
                if ($amount === '' || $name === '') {
                    continue; // Skip silently without recording a formal error
                }

                $results['total']++;
                $month = trim($row[$monthIdx] ?? '');
                
                // --- TRAP 4: Fuzzy matching percentage ---
                $rawAmountType = strtolower(trim($row[$amountTypeIdx] ?? ''));
                if (str_contains($rawAmountType, '%') || str_contains($rawAmountType, 'percent') || str_contains($rawAmountType, 'percentage')) {
                    $amountType = 'percentage_of_basic';
                } elseif (str_contains($rawAmountType, 'fix')) {
                    $amountType = 'fixed';
                } else {
                    $amountType = $rawAmountType; 
                }
                
                $type = strtolower(trim($row[$typeIdx] ?? ''));
                $description = trim($row[$descriptionIdx] ?? '');
                
                $rawRecurring = strtolower(trim($row[$recurringIdx] ?? ''));
                $recurring = in_array($rawRecurring, ['yes', 'true', '1']) ? true : false;

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
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Invalid amount_type {$rawAmountType} for {$empIdRaw}"];
                    continue;
                }

                if (!is_numeric($amount) || $amount < 0) {
                    $results['errors'][] = ['row' => $results['total'], 'message' => "Invalid amount {$amount} for {$empIdRaw}"];
                    continue;
                }

                // --- TRAP 2: Double submissions ---
                PayrollLedger::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'ledger_month' => $month,
                        'name' => $name
                    ],
                    [
                        'type' => $type,
                        'amount_type' => $amountType,
                        'amount_value' => (float)$amount,
                        'description' => $description,
                        'is_recurring' => $recurring
                    ]
                );

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

        $query->orderByDesc('payroll_month')->orderByDesc('created_at');

        if ($request->has('per_page')) {
            $payslips = $query->paginate($request->integer('per_page', 15));
            return response()->json([
                'success' => true,
                'data' => $payslips->items(),
                'meta' => [
                    'current_page' => $payslips->currentPage(),
                    'last_page' => $payslips->lastPage(),
                    'total' => $payslips->total(),
                    'per_page' => $payslips->perPage()
                ]
            ]);
        }

        return response()->json($query->get());
    }

    public function generatePayslip(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        try {
            $employee = Employee::findOrFail($validated['employee_id']);
            $run = $this->payrollService->initializeRun($validated['payroll_month']);
            $payslip = $this->payrollService->processEmployee($employee, $run);
            
            return response()->json($payslip);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // (LEGACY batchGenerate and calculateMonthlyFull REMOVED - Use PayrollRunController instead)

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
        $payslip = Payslip::with('payrollRun')->findOrFail($id);

        if ($payslip->payrollRun && in_array($payslip->payrollRun->status, ['locked', 'paid'])) {
            return response()->json([
                'success' => false, 
                'message' => 'Cannot delete a payslip from a locked or paid payroll run. Rollback the run first.'
            ], 422);
        }

        $run = $payslip->payrollRun;
        $payslip->delete();

        if ($run) {
            $totals = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->selectRaw('
                    SUM(gross_pay) as gross,
                    SUM(net_pay)   as net,
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.paye"))         AS DECIMAL(12,2)))
                        + SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.nssf"))         AS DECIMAL(12,2)))
                        + SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.shif"))         AS DECIMAL(12,2)))
                        + SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.housing_levy")) AS DECIMAL(12,2)))
                        as total_statutory
                ')
                ->first();

            $run->update([
                'total_gross'     => $totals->gross ?? 0,
                'total_net'       => $totals->net ?? 0,
                'total_statutory' => $totals->total_statutory ?? 0,
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Payslip record removed and run totals updated']);
    }

    public function clearPayslips(Request $request)
    {
        $validated = $request->validate([
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        // Check if any associated runs for this month are locked/paid
        $lockedRunsExist = PayrollRun::where('payroll_month', $validated['payroll_month'])
            ->whereIn('status', ['locked', 'paid'])
            ->exists();

        if ($lockedRunsExist) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clear payslips for this month because a locked or paid payroll run exists. Rollback those runs first.'
            ], 422);
        }

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
            ->whereIn('status', ['generated', 'paid'])
            ->whereHas('employee', fn($q) => $q->whereRaw("LOWER(payment_method) = 'bank'"))
            ->orderBy('id')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="Bank_Remittance_' . $validated['payroll_month'] . '.csv"',
        ];

        $callback = function() use ($payslips, $validated) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Beneficiary Bank Code', 'Beneficiary Name', 'Beneficiary Account', 'Amount', 'Currency', 'Remarks']);

            foreach ($payslips as $slip) {
                $bankCode   = $slip->employee->bank_code ?? '';
                $accountNo  = $slip->employee->account_number ?? '';

                // Skip rows that will fail bank upload validation
                if (empty($bankCode) || empty($accountNo)) {
                    continue;
                }

                fputcsv($file, [
                    $bankCode,
                    trim($slip->employee->first_name . ' ' . $slip->employee->last_name),
                    $accountNo,
                    number_format((float)$slip->net_pay, 2, '.', ''),
                    'KES',
                    'Salary ' . $validated['payroll_month'],
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
            ->whereIn('status', ['generated', 'paid'])
            ->whereHas('employee', fn($q) => $q->whereRaw("LOWER(payment_method) LIKE '%mpesa%'"))
            ->orderBy('id')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="MPESA_Remittance_' . $validated['payroll_month'] . '.csv"',
        ];

        $callback = function() use ($payslips, $validated) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['To (Phone number)', 'Amount', 'Remarks', 'Beneficiary (Name)']);

            foreach ($payslips as $slip) {
                $raw = $slip->employee->phone ?? '';

                if (empty($raw)) {
                    continue; // Safaricom rejects blank phone rows
                }

                // Normalise to 254XXXXXXXXX (strip +, spaces, leading 0)
                $phone = preg_replace('/\D/', '', $raw);
                if (str_starts_with($phone, '0')) {
                    $phone = '254' . substr($phone, 1);
                } elseif (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
                    $phone = '254' . $phone;
                }

                fputcsv($file, [
                    $phone,
                    (int) round((float) $slip->net_pay), // MPESA requires whole numbers
                    'Salary ' . $validated['payroll_month'],
                    trim($slip->employee->first_name . ' ' . $slip->employee->last_name),
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
                
                $finalPaye  = (float)($tax['paye'] ?? 0);
                $relief     = (float)($tax['personal_relief'] ?? 0) + (float)($tax['insurance_relief'] ?? 0);
                // Use persisted raw band tax; fall back to reconstruction for payslips generated before this fix
                $taxCharged = (float)($tax['calculated_paye'] ?? ($finalPaye + $relief));

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

    // (LEGACY getComplianceSummary and markAsPaid REMOVED)
}
