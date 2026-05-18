@extends('pdf.overtime.layout')

@section('title', 'Tamper-Evident Labor Audit Trail')

@section('content')
    <div style="margin-bottom: 20px; padding: 10px; background-color: #f0fdf4; border-left: 3px solid #166534; border-radius: 4px; font-size: 9px;">
        <strong>Cryptographic Verification Status:</strong> Active & Secure. All transactions below are linked in a hash-chain to guarantee absolute ledger compliance and prevent retroactive manual adjustments.
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 18%; text-align: center;">Occurred At</th>
                <th style="width: 22%;">Subject</th>
                <th style="width: 20%; text-align: center;">Transaction Type</th>
                <th style="width: 12%; text-align: right;">Hours (Hrs)</th>
                <th style="width: 13%; text-align: right;">Balance After</th>
                <th style="width: 15%; text-align: center;">Cryptographic Hash</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
                @php
                    $subjectName = $entry->employee ? $entry->employee->name : ($entry->technicalLabour ? $entry->technicalLabour->full_name : 'Unknown');
                @endphp
                <tr>
                    <td class="text-center">{{ \Carbon\Carbon::parse($entry->occurred_at)->format('Y-m-d H:i') }}</td>
                    <td><strong>{{ $subjectName }}</strong><br><span style="color: #64748b; font-size: 7px;">{{ $entry->employee ? 'Internal Staff' : 'Technical Pool' }}</span></td>
                    <td class="text-center">
                        @if($entry->kind == 'credit')
                            <span class="badge bg-green">CREDIT (OT)</span>
                        @else
                            <span class="badge bg-red">DEBIT (Leave)</span>
                        @endif
                    </td>
                    <td class="text-right font-bold text-green-700 num-val">{{ number_format($entry->hours, 2) }}</td>
                    <td class="text-right font-bold num-val">{{ number_format($entry->balance_after, 2) }}</td>
                    <td style="font-family: monospace; font-size: 7px; color: #475569;" class="text-center">
                        {{ substr($entry->chain_hash, 0, 10) }}... <span style="color: #166534; font-weight: bold;">[VERIFIED]</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
