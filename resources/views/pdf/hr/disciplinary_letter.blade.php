@extends('pdf.layouts.document')

@section('title', 'Disciplinary')
@section('subtitle', $heading)

@section('meta')
    <tr><td class="k">Case No</td><td class="v">DC/{{ str_pad($case->id, 5, '0', STR_PAD_LEFT) }}</td></tr>
    <tr><td class="k">Date</td><td class="v">{{ $issuedOn->format('d/m/Y') }}</td></tr>
@endsection

@section('content')
    <div class="doc-title">{{ $heading }}</div>

    <div class="letter">
        <p class="ref">
            <span class="font-bold">PRIVATE &amp; CONFIDENTIAL</span><br/>
            To: <span class="font-bold">{{ $employee?->first_name }} {{ $employee?->last_name }}</span>
            @if($employee?->position) &mdash; {{ $employee->position }} @endif
            @if($employee?->department?->name) ({{ $employee->department->name }}) @endif
        </p>

        <p>{{ $intro }}</p>

        <div class="section-header">Matter / Allegation</div>
        <table class="data-table">
            @if($case->offense_category)
            <tr>
                <th style="width: 25%;">Category</th>
                <td>{{ $case->offense_category }}</td>
            </tr>
            @endif
            <tr>
                <th>Date Reported</th>
                <td>{{ optional($case->date_reported)->format('d F Y') ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Particulars</th>
                <td>{{ $case->allegations ?? 'N/A' }}</td>
            </tr>
        </table>

        @if($bodyText)
            <div class="section-header">
                @if($type === 'show_cause') Notice
                @elseif($type === 'warning') Warning
                @else Decision
                @endif
            </div>
            <p style="white-space: pre-line;">{{ $bodyText }}</p>
        @endif

        @if($type === 'show_cause')
            <p class="mt-4">You are required to submit your written response within the period
               stipulated by Company policy. Failure to respond may result in the matter being
               determined in your absence.</p>
        @endif

        <div class="signature">
            <div class="line">Authorised Signatory</div>
            <p style="margin-top: 4px; font-size: 9px;" class="text-gray-600">
                Human Resources Department<br/>
                Woodnork Green Ltd
            </p>
        </div>

        <table style="margin-top: 28px; width: 100%;">
            <tr>
                <td style="width: 50%;">
                    <div class="signature" style="margin-top: 10px;">
                        <div class="line">Employee Acknowledgement</div>
                        <p style="margin-top: 4px; font-size: 8.5px;" class="text-gray-500">Name, Signature &amp; Date</p>
                    </div>
                </td>
                <td style="width: 50%;"></td>
            </tr>
        </table>
    </div>
@endsection
