@extends('pdf.layouts.document')

@section('title', 'Payslip')
@section('subtitle', 'Official Pay Advice &mdash; ' . $payslip->payroll_month)

@section('meta')
    <tr><td class="k">Month</td><td class="v">{{ $payslip->payroll_month }}</td></tr>
    <tr><td class="k">Ref</td><td class="v text-red-600">PS-{{ $payslip->id }}-{{ str_replace('-', '', $payslip->payroll_month) }}</td></tr>
@endsection

@section('content')
    @php
        $exemptions = $payslip->tax_breakdown['exemptions'] ?? [];
        $isExempt = fn ($code) => in_array($code, $exemptions, true);

        $totalDeductions = $payslip->tax_breakdown['nssf'] +
                          $payslip->tax_breakdown['shif'] +
                          $payslip->tax_breakdown['housing_levy'] +
                          $payslip->tax_breakdown['paye'];
        foreach($payslip->ledger_breakdown as $l) {
            if($l['type'] === 'deduction') $totalDeductions += $l['amount'];
        }
    @endphp

    <div class="section-header">Employee Details</div>
    <table class="data-table">
        <tr>
            <th style="width: 18%;">Employee Name</th>
            <td style="width: 32%;" class="font-bold">{{ $payslip->employee->first_name }} {{ $payslip->employee->last_name }}</td>
            <th style="width: 18%;">Employee ID</th>
            <td style="width: 32%;">{{ $payslip->employee->employee_id }}</td>
        </tr>
        <tr>
            <th>Department</th>
            <td>{{ $payslip->employee->department->name ?? 'Unassigned' }}</td>
            <th>Position</th>
            <td>{{ $payslip->employee->position }}</td>
        </tr>
        <tr>
            <th>KRA PIN</th>
            <td>{{ $payslip->employee->kra_pin ?? 'N/A' }}</td>
            <th>NSSF No.</th>
            <td>{{ $payslip->employee->nssf_id ?? 'N/A' }}</td>
        </tr>
    </table>

    <div class="section-header">Earnings &amp; Additions</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Basic Salary</td>
                <td class="text-right num-val">{{ number_format($payslip->basic_salary, 2) }}</td>
            </tr>
            @foreach($payslip->ledger_breakdown as $ledger)
                @if($ledger['type'] === 'addition')
                <tr>
                    <td>{{ $ledger['name'] }}</td>
                    <td class="text-right num-val">{{ number_format($ledger['amount'], 2) }}</td>
                </tr>
                @endif
            @endforeach
            <tr class="total-row">
                <td>GROSS PAY</td>
                <td class="text-right num-val">{{ number_format($payslip->gross_pay, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-header">Deductions &mdash; Statutory &amp; Others</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>NSSF (Social Security)</td>
                <td class="text-right num-val">@if($isExempt('nssf'))Exempt@else{{ number_format($payslip->tax_breakdown['nssf'], 2) }}@endif</td>
            </tr>
            <tr>
                <td>SHIF (Health Insurance)</td>
                <td class="text-right num-val">@if($isExempt('shif'))Exempt@else{{ number_format($payslip->tax_breakdown['shif'], 2) }}@endif</td>
            </tr>
            <tr>
                <td>Housing Levy</td>
                <td class="text-right num-val">@if($isExempt('housing_levy'))Exempt@else{{ number_format($payslip->tax_breakdown['housing_levy'], 2) }}@endif</td>
            </tr>
            <tr>
                <td>PAYE (Income Tax)</td>
                <td class="text-right num-val">@if($isExempt('paye'))Exempt@else{{ number_format($payslip->tax_breakdown['paye'], 2) }}@endif</td>
            </tr>
            @foreach($payslip->ledger_breakdown as $ledger)
                @if($ledger['type'] === 'deduction')
                <tr>
                    <td>{{ $ledger['name'] }}</td>
                    <td class="text-right num-val">{{ number_format($ledger['amount'], 2) }}</td>
                </tr>
                @endif
            @endforeach
            <tr class="total-row">
                <td>TOTAL DEDUCTIONS</td>
                <td class="text-right num-val">{{ number_format($totalDeductions, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-header">Net Pay</div>
    <table class="data-table">
        <tbody>
            <tr class="total-row">
                <td>NET PAY DISBURSABLE</td>
                <td class="text-right num-val">KES {{ number_format($payslip->net_pay, 2) }}</td>
            </tr>
            <tr>
                <th style="width: 50%;">Payment Method</th>
                <td>{{ strtoupper(str_replace('_', ' ', $payslip->employee->payment_method ?? 'BANK')) }}</td>
            </tr>
        </tbody>
    </table>

    @if($payslip->notes)
    <p class="text-gray-700" style="font-size: 9px; margin-top: 4px;"><span class="font-bold">Notes:</span> {{ $payslip->notes }}</p>
    @endif

    <p class="text-gray-600" style="font-size: 8.5px; margin-top: 4px;">
        PAYE (Income Tax) is shown net of personal and insurance relief.
        @if(count($exemptions)) This employee is exempt from: {{ strtoupper(implode(', ', str_replace('_', ' ', $exemptions))) }}. @endif
        This is a computer-generated document and does not require a physical signature.
    </p>
@endsection
