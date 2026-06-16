@extends('pdf.overtime.layout')

@section('title', 'Project Labor Allocation')

@section('content')
    <div style="margin-bottom: 20px; padding: 10px; background-color: #f0fdf4; border-left: 3px solid #166534; border-radius: 4px; font-size: 9px;">
        <strong>Project Labor Summary:</strong> Direct allocation of approved overtime hours billed to individual active projects. Use this data for project budget cost-tracking, BCR reconciliations, and client invoicing.
    </div>

    @foreach($projects as $project)
        <div style="margin-bottom: 25px; border: 1px solid #94a3b8; padding: 12px; border-radius: 4px; page-break-inside: avoid; background-color: #ffffff;">
            <h3 style="margin-top: 0; color: #166534; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; font-size: 11px;">
                PROJECT: {{ $project->title ?? $project->name ?? 'Untitled' }} ({{ $project->enquiry_number ?? $project->job_number ?? $project->project_code ?? 'N/A' }})
            </h3>
            
            <table class="data-table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="width: 20%; text-align: center;">Date Billed</th>
                        <th style="width: 35%;">Personnel</th>
                        <th style="width: 25%;">Labor Type</th>
                        <th style="width: 20%; text-align: right;">Hours Billed</th>
                    </tr>
                </thead>
                <tbody>
                    @php $totalHours = 0; @endphp
                    @foreach($project->otEntries as $log)
                        @php 
                            $totalHours += $log->hours; 
                            $name = $log->employee ? $log->employee->name : ($log->technicalLabour ? $log->technicalLabour->full_name : 'Unknown');
                            $type = $log->employee ? 'Internal Staff' : 'Technical Pool (Contractor)';
                        @endphp
                        <tr>
                            <td class="text-center">{{ \Carbon\Carbon::parse($log->work_date)->format('Y-m-d') }}</td>
                            <td><strong>{{ $name }}</strong></td>
                            <td>{{ $type }}</td>
                            <td class="text-right font-bold num-val">{{ number_format($log->hours, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3" class="text-right uppercase"><strong>Total Billed Hours:</strong></td>
                        <td class="text-right text-green-700 num-val" style="font-size: 10px;"><strong>{{ number_format($totalHours, 2) }}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endforeach
@endsection
