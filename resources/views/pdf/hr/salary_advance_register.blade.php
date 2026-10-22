@extends('pdf.layouts.document')

@php
    $kes = fn ($value) => number_format((float) $value, 2);
    $method = fn ($value) => ['mobile_money' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque', 'cash' => 'Cash'][$value] ?? ($value ?: '—');
@endphp

@section('title', 'Salary Advance Register')
@section('subtitle', 'Salary advance applications for disbursement and records')

@section('styles')
    <style>
        .data-table td, .data-table th { font-size: 8px; padding: 3px 4px; }
        .flag { color: #b91c1c; font-weight: bold; }
        .row-flag td { background-color: #fef2f2; }
        .muted { color: #6b7280; }
        .filters { font-size: 8.5px; color: #374151; margin: 0 0 6px 0; }
        .summary td { width: 16.6%; text-align: center; }
        .summary .label { font-size: 7.5px; color: #6b7280; text-transform: uppercase; }
        .summary .value { font-size: 11px; font-weight: bold; color: #111827; }
        .sign-table { width: 100%; margin-top: 30px; }
        .sign-table td { width: 33%; padding-right: 24px; vertical-align: bottom; font-size: 8.5px; }
        .sign-line { border-top: 1px solid #111827; padding-top: 3px; }
        .avoid-break { page-break-inside: avoid; }
    </style>
@endsection

@section('meta')
    <tr><td class="k">Applications</td><td class="v">{{ $totals['count'] }}</td></tr>
    <tr><td class="k">Total KES</td><td class="v">{{ $kes($totals['amount']) }}</td></tr>
    <tr><td class="k">Prepared by</td><td class="v">{{ $generatedBy }}</td></tr>
@endsection

@section('content')
    <p class="filters"><b>Filters:</b> {{ $filterLines ? implode(' · ', $filterLines) : 'none (all applications)' }} &nbsp;·&nbsp; <b>Generated:</b> {{ $generatedAt }}</p>

    <table class="data-table summary">
        <tr>
            <td><div class="label">Applications</div><div class="value">{{ $totals['count'] }}</div></td>
            <td><div class="label">Total requested</div><div class="value">{{ $kes($totals['amount']) }}</div></td>
            <td><div class="label">Awaiting payout</div><div class="value">{{ $totals['due_count'] }} · {{ $kes($totals['due_amount']) }}</div></td>
            <td><div class="label">Paid out</div><div class="value">{{ $kes($totals['paid_amount']) }}</div></td>
            <td><div class="label">Recovered</div><div class="value">{{ $kes($totals['recovered']) }}</div></td>
            <td><div class="label">Still to recover</div><div class="value">{{ $kes($totals['outstanding']) }}</div></td>
        </tr>
    </table>

    <div class="section-header">1. For disbursement — approved, not yet paid</div>
    @if($totals['due_missing'])
        <p class="flag" style="font-size: 8.5px; margin: 0 0 4px 0;">{{ $totals['due_missing'] }} advance{{ $totals['due_missing'] === 1 ? ' is' : 's are' }} missing payment details (shaded) — complete them in HR before paying.</p>
    @endif
    @if($totals['due_self_approved'])
        <p class="flag" style="font-size: 8.5px; margin: 0 0 4px 0;">{{ $totals['due_self_approved'] }} advance{{ $totals['due_self_approved'] === 1 ? ' was' : 's were' }} approved by the applicant — get an independent approval before paying.</p>
    @endif
    <table class="data-table">
        <thead>
            <tr>
                <th>Ref</th><th>Staff No.</th><th>Employee</th><th>ID No.</th><th>Department</th>
                <th class="text-right">Amount (KES)</th><th>Pay via</th><th>Bank / branch</th><th>Account / phone</th>
                <th>Approved by</th><th>Recover from</th><th>Check</th>
            </tr>
        </thead>
        <tbody>
            @forelse($due as $row)
                <tr class="{{ $row['checks'] ? 'row-flag' : '' }}">
                    <td class="font-bold" style="white-space: nowrap;">{{ $row['reference'] }}</td>
                    <td>{{ $row['staff_no'] ?? '—' }}</td>
                    <td>{{ $row['employee'] }}</td>
                    <td>{{ $row['id_number'] ?? '—' }}</td>
                    <td>{{ $row['department'] ?? '—' }}</td>
                    <td class="text-right num-val font-bold">{{ $kes($row['amount']) }}</td>
                    <td>{{ $method($row['payment_method']) }}</td>
                    <td>{{ trim(($row['bank_name'] ?? '').' '.($row['bank_branch'] ? '· '.$row['bank_branch'] : '')) ?: '—' }}{{ $row['bank_code'] ? ' ('.$row['bank_code'].')' : '' }}</td>
                    <td>{{ $row['payment_method'] === 'mobile_money' ? ($row['phone'] ?? '—') : ($row['account_number'] ?? '—') }}</td>
                    <td>{{ $row['decided_by'] ?? '—' }}<br><span class="muted">{{ $row['decided_on'] ? \Carbon\Carbon::parse($row['decided_on'])->format('d M Y') : '' }}</span></td>
                    <td>{{ $row['target_month'] }}</td>
                    <td class="{{ $row['checks'] ? 'flag' : '' }}">{{ $row['checks'] ?? 'OK' }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="text-center text-gray-600">No approved advances are waiting for payout under these filters.</td></tr>
            @endforelse
            @if(count($due))
                <tr class="total-row">
                    <td colspan="5">TOTAL TO DISBURSE ({{ count($due) }})</td>
                    <td class="text-right num-val">{{ $kes($totals['due_amount']) }}</td>
                    <td colspan="6"></td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="section-header">2. Register — all filtered applications</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Ref</th><th>Submitted</th><th>Employee</th><th>Department</th>
                <th class="text-right">Amount</th><th class="text-right">% of salary</th><th>Recover from</th><th>Repayment</th>
                <th>Status</th><th>Decided by</th><th>Paid on</th><th class="text-right">Recovered</th><th class="text-right">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="font-bold" style="white-space: nowrap;">{{ $row['reference'] }}</td>
                    <td>{{ $row['submitted_on'] ? \Carbon\Carbon::parse($row['submitted_on'])->format('d M Y') : '—' }}</td>
                    <td>{{ $row['employee'] }}<br><span class="muted">{{ $row['staff_no'] }}</span></td>
                    <td>{{ $row['department'] ?? '—' }}</td>
                    <td class="text-right num-val">{{ $kes($row['amount']) }}</td>
                    <td class="text-right">{{ $row['percent_of_salary'] !== null ? number_format($row['percent_of_salary'] * 100, 1).'%' : '—' }}</td>
                    <td>{{ $row['target_month'] }}</td>
                    <td>{{ $row['repayment'] }}</td>
                    <td>{{ $row['stage'] }}</td>
                    <td>{{ $row['decided_by'] ?? '—' }}<br><span class="muted">{{ $row['decided_on'] ? \Carbon\Carbon::parse($row['decided_on'])->format('d M Y') : '' }}</span></td>
                    <td>{{ $row['paid_on'] ? \Carbon\Carbon::parse($row['paid_on'])->format('d M Y') : '—' }}@if($row['payment_reference'])<br><span class="muted">{{ $row['payment_reference'] }}</span>@endif</td>
                    <td class="text-right num-val">{{ $kes($row['recovered']) }}</td>
                    <td class="text-right num-val">{{ $row['outstanding'] !== null ? $kes($row['outstanding']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="13" class="text-center text-gray-600">No salary advance applications match these filters.</td></tr>
            @endforelse
            @if(count($rows))
                <tr class="total-row">
                    <td colspan="4">TOTAL ({{ count($rows) }})</td>
                    <td class="text-right num-val">{{ $kes($totals['amount']) }}</td>
                    <td colspan="6"></td>
                    <td class="text-right num-val">{{ $kes($totals['recovered']) }}</td>
                    <td class="text-right num-val">{{ $kes($totals['outstanding']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="sign-table avoid-break">
        <tr>
            <td><div class="sign-line">Prepared by (HR): {{ $generatedBy }}</div><span class="muted">Signature &amp; date</span></td>
            <td><div class="sign-line">Approved by</div><span class="muted">Name, signature &amp; date</span></td>
            <td><div class="sign-line">Received by (Finance)</div><span class="muted">Name, signature &amp; date</span></td>
        </tr>
    </table>
@endsection
