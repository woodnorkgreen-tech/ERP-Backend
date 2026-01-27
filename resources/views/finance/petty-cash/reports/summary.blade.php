<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Petty Cash Summary Report</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #444; padding-bottom: 10px; }
        .logo { font-size: 24px; font-weight: bold; color: #1a56db; }
        .report-title { font-size: 18px; margin-top: 5px; text-transform: uppercase; }
        .meta { margin-bottom: 20px; }
        .meta-table { width: 100%; border-collapse: collapse; }
        .meta-table td { padding: 5px 0; }
        .summary-boxes { margin-bottom: 30px; }
        .summary-box { 
            display: inline-block; 
            width: 23%; 
            padding: 10px; 
            background: #f3f4f6; 
            border-radius: 5px; 
            margin-right: 1%;
            text-align: center;
        }
        .box-title { font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 5px; }
        .box-value { font-size: 14px; font-weight: bold; }
        .section-title { font-size: 14px; font-weight: bold; margin: 20px 0 10px; border-left: 4px solid #1a56db; padding-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #f9fafb; padding: 10px; text-align: left; font-size: 10px; text-transform: uppercase; border-bottom: 2px solid #e5e7eb; }
        td { padding: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .text-right { text-align: right; }
        .amount { font-family: monospace; font-weight: bold; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 5px; }
        .badge { padding: 2px 6px; border-radius: 10px; font-size: 9px; font-weight: bold; }
        .bg-green { background: #dcfce7; color: #166534; }
        .bg-blue { background: #dbeafe; color: #1e40af; }
        .bg-red { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">ANTIGRAVITY ERP</div>
        <div class="report-title">Petty Cash Summary Report</div>
    </div>

    <div class="meta">
        <table class="meta-table">
            <tr>
                <td width="50%"><strong>Reporting Period:</strong> {{ \Carbon\Carbon::parse($period['start_date'])->format('d M Y') }} - {{ \Carbon\Carbon::parse($period['end_date'])->format('d M Y') }}</td>
                <td width="50%" class="text-right"><strong>Generated At:</strong> {{ \Carbon\Carbon::parse($generated_at)->format('d M Y H:i:s') }}</td>
            </tr>
        </table>
    </div>

    <div class="summary-boxes">
        <div class="summary-box">
            <div class="box-title">Total Top-ups</div>
            <div class="box-value">KES {{ number_format($summary['total_top_ups'], 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="box-title">Total Disbursements</div>
            <div class="box-value">KES {{ number_format($summary['total_disbursements'], 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="box-title">Net Balance</div>
            <div class="box-value">KES {{ number_format($summary['net_balance'], 2) }}</div>
        </div>
        <div class="summary-box">
            <div class="box-title">Txn Count</div>
            <div class="box-value">{{ $summary['disbursement_count'] + $summary['top_up_count'] }}</div>
        </div>
    </div>

    <div class="section-title">Spending by Classification</div>
    <table>
        <thead>
            <tr>
                <th>Classification</th>
                <th class="text-right">Total Amount</th>
                <th class="text-right">Count</th>
                <th class="text-right">Percentage</th>
            </tr>
        </thead>
        <tbody>
            @foreach($spending_by_classification as $item)
            <tr>
                <td style="text-transform: capitalize;">{{ str_replace('_', ' ', $item['classification']) }}</td>
                <td class="text-right amount">KES {{ number_format($item['total_amount'], 2) }}</td>
                <td class="text-right">{{ $item['transaction_count'] }}</td>
                <td class="text-right">{{ $item['percentage'] }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Detailed Disbursements</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Receiver</th>
                <th>Account</th>
                <th>Project</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($detailed['disbursements']['data'] as $txn)
            <tr>
                <td>{{ \Carbon\Carbon::parse($txn->created_at)->format('d/m/Y') }}</td>
                <td>{{ $txn->receiver }}</td>
                <td>{{ $txn->account }}</td>
                <td>{{ $txn->project_name ?? '-' }}</td>
                <td class="text-right amount">KES {{ number_format($txn->amount, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center;">No disbursements found for this period.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Page 1 | Petty Cash Financial Report | Confidential
    </div>
</body>
</html>
