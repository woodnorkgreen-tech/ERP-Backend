@extends('pdf.overtime.layout')

@section('title', 'Technical Pool Cost Analysis')

@section('content')
    <div style="margin-bottom: 20px; padding: 10px; background-color: #f0fdf4; border-left: 3px solid #166534; border-radius: 4px; font-size: 9px;">
        <strong>Financial Comparison:</strong> A detailed comparative analysis of resource utilization and financial outlays between internal employees and external technical pool contractors for all approved overtime.
    </div>

    <div class="category-title">LABOR OUTLAY COMPARISON</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 40%;">Labor Category</th>
                <th style="width: 30%; text-align: right;">Total Hours Logged</th>
                <th style="width: 30%; text-align: right;">Estimated Financial Outlay (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Internal Staff (Employees)</strong><br><span style="color: #64748b; font-size: 7px;">Full-time contracted personnel</span></td>
                <td class="text-right font-bold num-val">{{ number_format($internalHours, 2) }}</td>
                <td class="text-right font-bold text-green-700 num-val">{{ number_format($internalCost, 2) }}</td>
            </tr>
            <tr>
                <td><strong>Technical Pool (Contractors)</strong><br><span style="color: #64748b; font-size: 7px;">External day-rate technical workforce</span></td>
                <td class="text-right font-bold num-val">{{ number_format($techHours, 2) }}</td>
                <td class="text-right font-bold text-green-700 num-val">{{ number_format($techCost, 2) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td><strong>AGGREGATED TOTALS:</strong></td>
                <td class="text-right text-green-700 num-val"><strong>{{ number_format($internalHours + $techHours, 2) }}</strong></td>
                <td class="text-right text-green-700 num-val" style="font-size: 10px;"><strong>{{ number_format($internalCost + $techCost, 2) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top: 30px; padding: 12px; border: 1px solid #94a3b8; border-radius: 4px; background-color: #f8fafc; font-size: 8px; line-height: 1.5; color: #475569; page-break-inside: avoid;">
        <strong style="color: #111827; font-size: 9px; display: block; margin-bottom: 5px;">Methodology & Calculation Rules:</strong>
        1. <strong>Internal Staff:</strong> Overtime costs are calculated using standard payroll base salary, divided by a standard 160 hours monthly labor output, scaled at a <strong>1.5x</strong> multiplier for overtime compliance.<br>
        2. <strong>Technical Pool:</strong> Contractor costs are mapped directly to their registered day-rate in the system, computed as an hourly rate based on a standard <strong>8-hour</strong> shift output.
    </div>
@endsection
