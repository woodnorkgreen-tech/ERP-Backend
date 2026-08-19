<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 30px;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }
        .header {
            padding-bottom: 20px;
            border-bottom: 3px double #0f172a;
            margin-bottom: 25px;
        }
        .logo-box {
            float: left;
        }
        .logo-img {
            height: 50px;
            margin-bottom: 5px;
        }
        .company-info {
            margin-top: 5px;
        }
        .company-name {
            font-size: 16px;
            font-weight: 900;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 1px;
        }
        .kra-pin {
            font-size: 9px;
            color: #64748b;
            font-weight: bold;
        }
        .document-title {
            float: right;
            text-align: right;
        }
        .document-title h2 {
            margin: 0;
            color: #0f172a;
            font-size: 22px;
            text-transform: uppercase;
            letter-spacing: -1px;
        }
        .period {
            font-size: 10px;
            color: #64748b;
            font-weight: bold;
            margin-top: 5px;
        }

        /* Summary Cards */
        .summary-grid {
            width: 100%;
            margin-bottom: 30px;
        }
        .summary-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px;
            border-radius: 8px;
            width: 22%;
            float: left;
            margin-right: 2%;
        }
        .summary-card:last-child {
            margin-right: 0;
            background-color: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
        }
        .summary-label {
            font-size: 8px;
            text-transform: uppercase;
            font-weight: 900;
            color: #64748b;
            margin-bottom: 4px;
            display: block;
        }
        .summary-value {
            font-size: 13px;
            font-weight: 900;
        }
        .last-card .summary-label {
            color: #94a3b8;
        }

        /* Sections */
        .section-title {
            font-size: 11px;
            font-weight: 900;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 1px;
            background-color: #f1f5f9;
            padding: 6px 10px;
            border-left: 4px solid #3b82f6;
            margin: 25px 0 12px 0;
        }

        /* Data Tables */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.data-table th {
            background-color: #f8fafc;
            text-align: left;
            padding: 10px 8px;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 900;
            font-size: 9px;
            color: #475569;
            text-transform: uppercase;
        }
        table.data-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .text-blue { color: #3b82f6; }
        .text-slate { color: #64748b; }

        /* Categorization Section */
        .analytics-box {
            width: 100%;
            margin-bottom: 20px;
        }
        .classification-row {
            border-bottom: 1px dashed #e2e8f0;
            padding: 6px 0;
        }
        .classification-label {
            float: left;
            font-weight: bold;
            text-transform: capitalize;
        }
        .classification-value {
            float: right;
            font-weight: 900;
        }

        /* Signatures */
        .footer {
            margin-top: 40px;
            page-break-inside: avoid;
        }
        .signature-grid {
            width: 100%;
            margin-top: 30px;
        }
        .signature-box {
            width: 30%;
            text-align: center;
        }
        .signature-line {
            border-bottom: 1px solid #0f172a;
            margin-bottom: 8px;
            height: 40px;
        }
        .signature-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            color: #64748b;
        }

        .timestamp {
            font-size: 8px;
            color: #94a3b8;
            margin-top: 50px;
            text-align: center;
            border-top: 1px dotted #e2e8f0;
            padding-top: 10px;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>
    <div class="header clearfix">
        <div class="logo-box">
            <img src="{{ public_path('woodnork-green-logo.png') }}" class="logo-img" alt="Woodnork Green logo"/>
            <div class="company-info">
                <div class="kra-pin">KRA PIN: P051451468C</div>
            </div>
        </div>
        <div class="document-title">
            <h2>{{ $title }}</h2>
            <div class="period">
                {{ \Carbon\Carbon::parse($period['start'])->format('d M Y') }} 
                &mdash; 
                {{ \Carbon\Carbon::parse($period['end'])->format('d M Y') }}
            </div>
        </div>
    </div>

    <!-- Summary InSights -->
    <div class="summary-grid clearfix">
        <div class="summary-card">
            <span class="summary-label">Opening Balance</span>
            <div class="summary-value text-slate">KES {{ number_format($opening_balance, 2) }}</div>
        </div>
        <div class="summary-card">
            <span class="summary-label">Total Inflows</span>
            <div class="summary-value text-blue">KES {{ number_format($total_in, 2) }}</div>
        </div>
        <div class="summary-card">
            <span class="summary-label">Total Outflows</span>
            <div class="summary-value" style="color: #ef4444;">(KES {{ number_format($total_out, 2) }})</div>
        </div>
        <div class="summary-card last-card">
            <span class="summary-label">Closing Balance</span>
            <div class="summary-value">KES {{ number_format($closing_balance, 2) }}</div>
        </div>
    </div>

    @if(count($classification_breakdown) > 0)
    <div class="section-title">Spending Categories Breakdown</div>
    <div class="analytics-box">
        @foreach($classification_breakdown as $cat => $data)
        <div class="classification-row clearfix">
            <span class="classification-label">{{ str_replace('_', ' ', $cat) }} ({{ $data['count'] }} tx)</span>
            <span class="classification-value">KES {{ number_format($data['total'], 2) }}</span>
        </div>
        @endforeach
    </div>
    @endif

    @if(count($top_ups) > 0)
    <div class="section-title">I. Record of Top-ups (Inflows)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th width="15%">Date</th>
                <th width="20%">Method</th>
                <th>Description / Reference</th>
                <th width="20%" class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($top_ups as $topUp)
            <tr>
                <td>{{ \Carbon\Carbon::parse($topUp['created_at'])->format('d/m/Y') }}</td>
                <td>
                    <span class="font-bold">{{ ucfirst(str_replace('_', ' ', $topUp['payment_method'])) }}</span>
                    @if($topUp['transaction_code'])
                        <br><small class="text-slate">{{ $topUp['transaction_code'] }}</small>
                    @endif
                </td>
                <td>{{ $topUp['description'] }}</td>
                <td class="text-right font-bold">KES {{ number_format($topUp['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(count($disbursements) > 0)
    <div class="section-title">II. Record of Disbursements (Outflows)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th width="12%">Date</th>
                <th width="18%">Receiver / Payee</th>
                <th width="15%">Classification</th>
                <th>Purpose & Project Allocation</th>
                <th width="15%" class="text-right">Base</th>
                <th width="12%" class="text-right">Fees</th>
                <th width="15%" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($disbursements as $disb)
            <tr>
                <td>{{ \Carbon\Carbon::parse($disb['date_disbursed'] ?? $disb['created_at'])->format('d/m/Y') }}</td>
                <td>
                    <div class="font-bold text-slate">{{ $disb['receiver'] }}</div>
                    @if($disb['payment_method'])
                        <small style="text-transform: uppercase; font-size: 8px;">{{ str_replace('_', ' ', $disb['payment_method']) }}</small>
                    @endif
                </td>
                <td style="text-transform: capitalize;">{{ str_replace('_', ' ', $disb['classification']) }}</td>
                <td>
                    {{ $disb['description'] }}
                    <div style="margin-top: 4px; border-top: 1px solid #f8fafc; padding-top: 2px;">
                        <span class="font-bold text-blue">
                            @if($disb['job_number'])
                                [{{ $disb['job_number'] }}]
                            @endif
                            {{ $disb['project_name'] ?: 'General / Office' }}
                        </span>
                        @if($disb['venue'])
                            <br><small class="text-slate">@ {{ $disb['venue'] }}</small>
                        @endif
                    </div>
                </td>
                <td class="text-right">{{ number_format($disb['amount'], 2) }}</td>
                <td class="text-right text-slate">{{ number_format($disb['transaction_cost'] ?? 0, 2) }}</td>
                <td class="text-right font-bold">KES {{ number_format((float)$disb['amount'] + (float)($disb['transaction_cost'] ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">
        <table class="signature-grid">
            <tr>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Prepared By (Cashier)</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Finance Manager</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Internal Audit</div>
                </td>
            </tr>
        </table>
        
        <div class="timestamp">
            This is a system-generated report for official auditing purposes. <br>
            Woodnork Green ERP System | Generated: {{ date('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>
