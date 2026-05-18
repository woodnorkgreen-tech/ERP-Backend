@extends('pdf.overtime.layout')

@section('title', 'Fatigue & Burnout Risk Matrix')

@section('content')
    <div style="margin-bottom: 20px; padding: 10px; background-color: #fee2e2; border-left: 3px solid #dc2626; border-radius: 4px; font-size: 9px; color: #7f1d1d;">
        <strong>Safety & Fatigue Advisory:</strong> The personnel listed below have exceeded the corporate threshold of <strong>{{ $threshold }} overtime hours</strong> within the last <strong>{{ $days }} days</strong>. Overtime scheduling should be paused immediately for these individuals to prevent burnout, quality degradation, and safety risks.
    </div>

    <div class="category-title">01. INTERNAL STAFF (EMPLOYEES)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 35%;">Employee Name</th>
                <th style="width: 25%;">Department</th>
                <th style="width: 20%; text-align: right;">OT Hours ({{ $days }} days)</th>
                <th style="width: 20%; text-align: center;">Risk Assessment</th>
            </tr>
        </thead>
        <tbody>
            @forelse($employees as $emp)
                <tr>
                    <td><strong>{{ $emp->name }}</strong></td>
                    <td>{{ $emp->department ? $emp->department->name : 'N/A' }}</td>
                    <td class="text-right font-bold text-red-600 num-val">
                        {{ number_format($emp->ot_entries_sum_hours, 2) }}
                    </td>
                    <td class="text-center"><span class="badge bg-red">CRITICAL RISK</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="color: #64748b;">No internal staff flagged for fatigue risk. All staff are within safe operating limits.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="category-title" style="margin-top: 25px;">02. TECHNICAL LABOUR POOL (CONTRACTORS)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 35%;">Contractor Name</th>
                <th style="width: 25%;">Specialization</th>
                <th style="width: 20%; text-align: right;">OT Hours ({{ $days }} days)</th>
                <th style="width: 20%; text-align: center;">Risk Assessment</th>
            </tr>
        </thead>
        <tbody>
            @forelse($techs as $tech)
                <tr>
                    <td><strong>{{ $tech->full_name }}</strong></td>
                    <td>{{ $tech->specialization ?? 'N/A' }}</td>
                    <td class="text-right font-bold text-red-600 num-val">
                        {{ number_format($tech->ot_entries_sum_hours, 2) }}
                    </td>
                    <td class="text-center"><span class="badge bg-red">CRITICAL RISK</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="color: #64748b;">No technical pool contractors flagged for fatigue risk.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
