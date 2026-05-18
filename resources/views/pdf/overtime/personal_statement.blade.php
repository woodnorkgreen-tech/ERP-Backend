@extends('pdf.overtime.layout')

@section('title', 'Personal Time-Off Statement')

@section('content')
    <div style="margin-bottom: 25px; padding: 15px; background-color: #f0fdf4; border-radius: 4px; border: 1px solid #94a3b8; border-left: 3px solid #166534; page-break-inside: avoid;">
        <h2 style="margin-top: 0; color: #166534; font-size: 14px;">
            @if($type === 'tech')
                {{ $subject->full_name }}
            @else
                {{ $subject->name }}
            @endif
        </h2>
        <div style="color: #475569; font-size: 9px; line-height: 1.6;">
            @if($type === 'tech')
                <strong>Category:</strong> Technical Pool Contractor <br>
                <strong>Specialization:</strong> {{ $subject->specialization ?? 'General Labor' }}
            @else
                <strong>Employee ID:</strong> {{ $subject->employee_id ?? 'N/A' }} <br>
                <strong>Position:</strong> {{ $subject->position ?? 'N/A' }} <br>
                <strong>Department:</strong> {{ $subject->department ? $subject->department->name : 'N/A' }}
            @endif
        </div>
        
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #cbd5e1;">
            <strong style="font-size: 11px;">Current Available Balance: </strong>
            <span style="font-size: 12px; color: #166534; font-weight: bold;" class="num-val">{{ number_format($subject->ot_balance, 2) }} Hrs</span>
        </div>
    </div>

    <div class="category-title">ACCOUNT TRANSACTION HISTORY</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 18%; text-align: center;">Transaction Date</th>
                <th style="width: 42%;">Reference / Note</th>
                <th style="width: 15%; text-align: center;">Type</th>
                <th style="width: 12%; text-align: right;">Amount (Hrs)</th>
                <th style="width: 13%; text-align: right;">Running Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($ledger as $entry)
                <tr>
                    <td class="text-center">{{ \Carbon\Carbon::parse($entry->occurred_at)->format('Y-m-d') }}</td>
                    <td>
                        @if($entry->kind == 'credit')
                            OT Log on {{ \Carbon\Carbon::parse($entry->otEntry->work_date ?? $entry->occurred_at)->format('M d') }}
                            @if($entry->otEntry && $entry->otEntry->project)
                                - <span style="color: #475569;">{{ $entry->otEntry->project->title ?? $entry->otEntry->project->name ?? '' }}</span>
                            @endif
                        @else
                            Compensatory Leave on {{ \Carbon\Carbon::parse($entry->compensation->comp_date ?? $entry->occurred_at)->format('M d') }}
                        @endif
                    </td>
                    <td class="text-center">
                        @if($entry->kind == 'credit')
                            <span style="color: #166534; font-weight: bold;">+ Earned</span>
                        @else
                            <span style="color: #dc2626; font-weight: bold;">- Redeemed</span>
                        @endif
                    </td>
                    <td class="text-right font-bold {{ $entry->kind == 'credit' ? 'text-green-700' : 'text-red-600' }} num-val">
                        {{ $entry->kind == 'credit' ? '+' : '-' }}{{ number_format($entry->hours, 2) }}
                    </td>
                    <td class="text-right font-bold num-val">{{ number_format($entry->balance_after, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center" style="color: #64748b; padding: 15px 0;">No ledger transaction history found for this account.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
