@extends('pdf.layouts.document')

@php
    $kes = fn ($value) => number_format((float) $value, 2);
    $date = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('d M Y') : '—';
    // Where the money goes, in one cell: bank details or the M-Pesa number.
    $payTo = function (array $row) {
        if ($row['payment_method'] === 'mobile_money') return 'M-Pesa '.($row['phone'] ?: '—');
        if (in_array($row['payment_method'], ['cheque', 'cash'], true)) return ucfirst($row['payment_method']);
        $bank = trim(($row['bank_name'] ?? '').($row['bank_branch'] ? ', '.$row['bank_branch'] : '').($row['bank_code'] ? ' ('.$row['bank_code'].')' : ''));
        if (! $bank && ! $row['account_number']) return 'No bank details on record';
        return ($bank ?: 'Bank not recorded').' · A/C '.($row['account_number'] ?: 'not recorded');
    };
@endphp

@section('title', 'Salary Advance Register')
@section('subtitle', 'For disbursement and records')

@section('styles')
    <style>
        .data-table td, .data-table th { font-size: 8.5px; padding: 4px 5px; }
        .flag { color: #b91c1c; font-weight: bold; }
        .row-flag td { background-color: #fef2f2; }
        .muted { color: #6b7280; }
        .nowrap { white-space: nowrap; }
        .filters { font-size: 8.5px; color: #374151; margin: 0 0 6px 0; }
        .sign-table { width: 100%; margin-top: 30px; page-break-inside: avoid; }
        .sign-table td { width: 33%; padding-right: 24px; vertical-align: bottom; font-size: 8.5px; }
        .sign-line { border-top: 1px solid #111827; padding-top: 3px; }
    </style>
@endsection

@section('meta')
    <tr><td class="k">Applications</td><td class="v">{{ $totals['count'] }} · {{ $kes($totals['amount']) }}</td></tr>
    <tr><td class="k">To disburse</td><td class="v">{{ $totals['due_count'] }} · {{ $kes($totals['due_amount']) }}</td></tr>
    <tr><td class="k">Prepared by</td><td class="v">{{ $generatedBy }}</td></tr>
@endsection

@section('content')
    <p class="filters"><b>Filters:</b> {{ $filterLines ? implode(' · ', $filterLines) : 'none (all applications)' }} &nbsp;·&nbsp; <b>Generated:</b> {{ $generatedAt }}</p>

    <div class="section-header">1. For disbursement — approved, not yet paid</div>
    <table class="data-table">
        <thead>
            <tr><th>Ref</th><th>Employee</th><th class="text-right">Amount (KES)</th><th>Pay to</th><th>Approved by</th><th>Check</th></tr>
        </thead>
        <tbody>
            @forelse($due as $row)
                <tr class="{{ $row['checks'] ? 'row-flag' : '' }}">
                    <td class="font-bold nowrap">{{ $row['reference'] }}</td>
                    <td>{{ $row['employee'] }} <span class="muted">{{ $row['staff_no'] }}</span></td>
                    <td class="text-right num-val font-bold nowrap">{{ $kes($row['amount']) }}</td>
                    <td>{{ $payTo($row) }}</td>
                    <td>{{ $row['decided_by'] ?? '—' }}</td>
                    <td class="{{ $row['checks'] ? 'flag' : '' }}">{{ $row['checks'] ?? 'OK' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-gray-600">No approved advances are waiting for payout under these filters.</td></tr>
            @endforelse
            @if(count($due))
                <tr class="total-row">
                    <td colspan="2">TOTAL TO DISBURSE ({{ count($due) }})</td>
                    <td class="text-right num-val nowrap">{{ $kes($totals['due_amount']) }}</td>
                    <td colspan="3"></td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="section-header">2. Register</div>
    <table class="data-table">
        <thead>
            <tr><th>Ref</th><th>Employee</th><th class="text-right">Amount</th><th>Status</th><th>Paid on</th><th class="text-right">Outstanding</th></tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="font-bold nowrap">{{ $row['reference'] }}</td>
                    <td>{{ $row['employee'] }} <span class="muted">{{ $row['staff_no'] }}</span></td>
                    <td class="text-right num-val nowrap">{{ $kes($row['amount']) }}</td>
                    <td>{{ $row['stage'] }}</td>
                    <td class="nowrap">{{ $date($row['paid_on']) }}</td>
                    <td class="text-right num-val nowrap">{{ $row['outstanding'] !== null ? $kes($row['outstanding']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-gray-600">No salary advance applications match these filters.</td></tr>
            @endforelse
            @if(count($rows))
                <tr class="total-row">
                    <td colspan="2">TOTAL ({{ count($rows) }})</td>
                    <td class="text-right num-val nowrap">{{ $kes($totals['amount']) }}</td>
                    <td colspan="2"></td>
                    <td class="text-right num-val nowrap">{{ $kes($totals['outstanding']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="sign-table">
        <tr>
            <td><div class="sign-line">Prepared by (HR): {{ $generatedBy }}</div><span class="muted">Signature &amp; date</span></td>
            <td><div class="sign-line">Approved by</div><span class="muted">Name, signature &amp; date</span></td>
            <td><div class="sign-line">Received by (Finance)</div><span class="muted">Name, signature &amp; date</span></td>
        </tr>
    </table>
@endsection
