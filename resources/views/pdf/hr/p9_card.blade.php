@extends('pdf.layouts.document')

@section('title', 'P9A Tax Card')
@section('subtitle', 'Annual Tax Deduction Card &mdash; Year ' . $year)

@section('meta')
    <tr><td class="k">Year</td><td class="v">{{ $year }}</td></tr>
    <tr><td class="k">KRA PIN</td><td class="v">{{ $employee->kra_pin ?? 'N/A' }}</td></tr>
@endsection

@section('content')
    <div class="section-header">Employee &amp; Employer Details</div>
    <table class="data-table">
        <tr>
            <th style="width: 18%;">Employee Name</th>
            <td style="width: 32%;" class="font-bold">{{ $employee->first_name }} {{ $employee->last_name }}</td>
            <th style="width: 18%;">Employee PIN</th>
            <td style="width: 32%;">{{ $employee->kra_pin ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Employer</th>
            <td>Woodnork Green Ltd</td>
            <th>National ID</th>
            <td>{{ $employee->id_number ?? 'N/A' }}</td>
        </tr>
    </table>

    <div class="section-header">Monthly Tax Deduction Schedule</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Month</th>
                <th class="text-right">Basic Salary (A)</th>
                <th class="text-right">Benefits (B)</th>
                <th class="text-right">Gross Pay (D)</th>
                <th class="text-right">NSSF (E2)</th>
                <th class="text-right">Taxable Pay (H)</th>
                <th class="text-right">Tax Charged (I)</th>
                <th class="text-right">Relief (J)</th>
                <th class="text-right">PAYE (L)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="font-bold">{{ $row['month'] }}</td>
                    <td class="text-right num-val">{{ number_format($row['basic'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['benefits'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['gross'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['nssf'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['taxable'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['tax_charged'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['relief'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($row['paye'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-gray-600">No payslips found for {{ $year }}.</td></tr>
            @endforelse
            @if(count($rows))
                <tr class="total-row">
                    <td>TOTALS</td>
                    <td class="text-right num-val">{{ number_format($totals['basic'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['benefits'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['gross'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['nssf'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['taxable'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['tax_charged'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['relief'], 2) }}</td>
                    <td class="text-right num-val">{{ number_format($totals['paye'], 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <p class="text-gray-600" style="font-size: 8.5px; margin-top: 4px;">
        Columns follow the KRA P9A layout. Tax Charged (I) is PAYE before reliefs;
        Relief (J) combines personal and insurance relief; PAYE (L) is the net tax remitted.
    </p>
@endsection
