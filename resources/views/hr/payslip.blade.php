<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip - {{ $payslip->payroll_month }}</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; color: #334155; margin: 0; padding: 40px; }
        .header { border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 30px; }
        .company-name { font-size: 24px; font-weight: bold; color: #0f172a; }
        .payslip-title { font-size: 14px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        
        .info-grid { width: 100%; margin-bottom: 30px; }
        .info-label { font-size: 10px; font-weight: bold; color: #94a3b8; text-transform: uppercase; }
        .info-value { font-size: 12px; font-weight: bold; color: #1e293b; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { text-align: left; font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase; background: #f8fafc; padding: 12px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 12px; font-size: 12px; border-bottom: 1px solid #f1f5f9; }
        
        .amount { text-align: right; font-family: 'Courier', monospace; font-weight: bold; }
        .section-title { font-size: 12px; font-weight: bold; color: #3b82f6; margin-bottom: 10px; margin-top: 20px; }
        
        .summary-box { background: #f8fafc; padding: 20px; border-radius: 12px; margin-top: 30px; }
        .net-pay-row { font-size: 18px; font-weight: bold; color: #0f172a; border-top: 2px solid #e2e8f0; padding-top: 10px; margin-top: 10px; }
        
        .footer { margin-top: 50px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width: 100%; border: none; margin-bottom: 0;">
            <tr>
                <td style="border: none; padding: 0;">
                    <div class="company-name">WNG ERP</div>
                    <div class="payslip-title">Official Pay Advice</div>
                </td>
                <td style="border: none; padding: 0; text-align: right;">
                    <div style="font-size: 12px; font-weight: bold;">Month: {{ $payslip->payroll_month }}</div>
                    <div style="font-size: 10px; color: #64748b;">Ref: #PS-{{ $payslip->id }}-{{ str_replace('-', '', $payslip->payroll_month) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-grid" style="border: none;">
        <tr>
            <td style="border: none; width: 50%;">
                <div class="info-label">Employee Name</div>
                <div class="info-value">{{ $payslip->employee->first_name }} {{ $payslip->employee->last_name }}</div>
            </td>
            <td style="border: none; width: 50%;">
                <div class="info-label">Employee ID</div>
                <div class="info-value">{{ $payslip->employee->employee_id }}</div>
            </td>
        </tr>
        <tr>
            <td style="border: none;">
                <div class="info-label">Department</div>
                <div class="info-value">{{ $payslip->employee->department->name ?? 'Unassigned' }}</div>
            </td>
            <td style="border: none;">
                <div class="info-label">Position</div>
                <div class="info-value">{{ $payslip->employee->position }}</div>
            </td>
        </tr>
        <tr>
            <td style="border: none;">
                <div class="info-label">KRA PIN</div>
                <div class="info-value">{{ $payslip->employee->kra_pin ?? '—' }}</div>
            </td>
            <td style="border: none;">
                <div class="info-label">NSSF No.</div>
                <div class="info-value">{{ $payslip->employee->nssf_id ?? '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Earnings & Additions</div>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Basic Salary</td>
                <td class="amount">{{ number_format($payslip->basic_salary, 2) }}</td>
            </tr>
            @foreach($payslip->ledger_breakdown as $ledger)
                @if($ledger['type'] === 'addition')
                <tr>
                    <td>{{ $ledger['name'] }}</td>
                    <td class="amount">{{ number_format($ledger['amount'], 2) }}</td>
                </tr>
                @endif
            @endforeach
            <tr style="background: #f8fafc; font-weight: bold;">
                <td>GROSS PAY</td>
                <td class="amount">{{ number_format($payslip->gross_pay, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Deductions (Statutory &amp; Others)</div>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>NSSF (Social Security)</td>
                <td class="amount">({{ number_format($payslip->tax_breakdown['nssf'], 2) }})</td>
            </tr>
            <tr>
                <td>SHIF (Health Insurance)</td>
                <td class="amount">({{ number_format($payslip->tax_breakdown['shif'], 2) }})</td>
            </tr>
            <tr>
                <td>Housing Levy</td>
                <td class="amount">({{ number_format($payslip->tax_breakdown['housing_levy'], 2) }})</td>
            </tr>
            @if(isset($payslip->tax_breakdown['calculated_paye']) && $payslip->tax_breakdown['calculated_paye'] > 0)
            <tr style="color: #64748b; font-style: italic;">
                <td style="padding-left: 24px;">Tax on Taxable Pay</td>
                <td class="amount">({{ number_format($payslip->tax_breakdown['calculated_paye'], 2) }})</td>
            </tr>
            <tr style="color: #16a34a;">
                <td style="padding-left: 24px;">Personal Relief</td>
                <td class="amount">+{{ number_format($payslip->tax_breakdown['personal_relief'] ?? 0, 2) }}</td>
            </tr>
            @if(isset($payslip->tax_breakdown['insurance_relief']) && $payslip->tax_breakdown['insurance_relief'] > 0)
            <tr style="color: #16a34a;">
                <td style="padding-left: 24px;">Insurance Relief</td>
                <td class="amount">+{{ number_format($payslip->tax_breakdown['insurance_relief'], 2) }}</td>
            </tr>
            @endif
            @endif
            <tr>
                <td>PAYE (Income Tax)</td>
                <td class="amount">({{ number_format($payslip->tax_breakdown['paye'], 2) }})</td>
            </tr>
            @foreach($payslip->ledger_breakdown as $ledger)
                @if($ledger['type'] === 'deduction')
                <tr>
                    <td>{{ $ledger['name'] }}</td>
                    <td class="amount">({{ number_format($ledger['amount'], 2) }})</td>
                </tr>
                @endif
            @endforeach
            <tr style="background: #fef2f2; font-weight: bold; color: #dc2626;">
                <td>TOTAL DEDUCTIONS</td>
                <td class="amount">
                    @php
                        $totalDeductions = $payslip->tax_breakdown['nssf'] + 
                                          $payslip->tax_breakdown['shif'] + 
                                          $payslip->tax_breakdown['housing_levy'] + 
                                          $payslip->tax_breakdown['paye'];
                        foreach($payslip->ledger_breakdown as $l) {
                            if($l['type'] === 'deduction') $totalDeductions += $l['amount'];
                        }
                    @endphp
                    ({{ number_format($totalDeductions, 2) }})
                </td>
            </tr>
        </tbody>
    </table>

    <div class="summary-box">
        <table style="width: 100%; border: none; margin-bottom: 0;">
            <tr class="net-pay-row">
                <td style="border: none; padding: 0;">NET PAY DISBURSABLE</td>
                <td style="border: none; padding: 0; text-align: right;">KES {{ number_format($payslip->net_pay, 2) }}</td>
            </tr>
        </table>
        <div style="font-size: 10px; color: #64748b; margin-top: 10px;">
            Payment Method: {{ strtoupper(str_replace('_', ' ', $payslip->employee->payment_method ?? 'BANK')) }}
        </div>
    </div>

    @if($payslip->notes)
    <div style="margin-top: 20px; font-size: 11px;">
        <strong>Notes:</strong> {{ $payslip->notes }}
    </div>
    @endif

    <div class="footer">
        This is a computer-generated document and does not require a physical signature.<br>
        Generated on {{ date('Y-m-d H:i:s') }}
    </div>
</body>
</html>
