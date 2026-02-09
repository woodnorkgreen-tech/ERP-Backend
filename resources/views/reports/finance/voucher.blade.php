<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.4;
        }
        .header {
            border-bottom: 2px solid #444;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
        }
        .document-title {
            float: right;
            text-align: right;
        }
        .document-title h2 {
            margin: 0;
            color: #000;
        }
        .period {
            font-size: 10px;
            color: #666;
        }
        .summary-box {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .summary-table {
            width: 100%;
        }
        .summary-table td {
            padding: 3px 0;
        }
        .label {
            color: #666;
            font-weight: bold;
        }
        .value {
            text-align: right;
            font-weight: bold;
        }
        .total-row {
            border-top: 1px solid #ddd;
            margin-top: 5px;
            padding-top: 5px;
        }
        .total-value {
            font-size: 14px;
            color: #007bff;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            border-left: 4px solid #007bff;
            padding-left: 8px;
            margin: 20px 0 10px 0;
            text-transform: uppercase;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.data-table th {
            background-color: #eee;
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
        }
        table.data-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .text-right {
            text-align: right;
        }
        .footer {
            margin-top: 50px;
        }
        .signature-grid {
            width: 100%;
            margin-top: 40px;
        }
        .signature-box {
            width: 30%;
            text-align: center;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            margin-bottom: 5px;
        }
        .signature-label {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #666;
        }
        .timestamp {
            font-size: 9px;
            color: #999;
            margin-top: 30px;
            text-align: right;
        }
        .negative {
            color: #d9534f;
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
        <div style="float: left;">
            <img src="{{ public_path('logo-outline.png') }}" style="height: 65px; width: auto; margin-bottom: 5px; display: block;" alt="Woodnork Green Logo"/>
            <div class="company-name">WOODNORK GREEN LTD</div>
            <div style="font-size: 14px; color: #555;">Petty Cash Record</div>
        </div>
        <div class="document-title">
            <h2>{{ $title }}</h2>
            <div class="period">Period: {{ \Carbon\Carbon::parse($period['start'])->format('d M Y') }} - {{ \Carbon\Carbon::parse($period['end'])->format('d M Y') }}</div>
        </div>
    </div>

    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td class="label">Opening Balance:</td>
                <td class="value">KES {{ number_format($opening_balance, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Total Inflows (Top-ups):</td>
                <td class="value">KES {{ number_format($total_in, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Total Outflows (Disbursements):</td>
                <td class="value negative">(KES {{ number_format($total_out, 2) }})</td>
            </tr>
            <tr class="total-row">
                <td class="label" style="font-size: 14px; color: #333;">Closing Balance:</td>
                <td class="value total-value">KES {{ number_format($closing_balance, 2) }}</td>
            </tr>
        </table>
    </div>

    @if(count($top_ups) > 0)
    <div class="section-title">I. Top-ups (Inflow)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Method</th>
                <th>Description</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($top_ups as $topUp)
            <tr>
                <td>{{ \Carbon\Carbon::parse($topUp['created_at'])->format('d/m/Y') }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $topUp['payment_method'])) }}</td>
                <td>{{ $topUp['description'] }}</td>
                <td class="text-right">KES {{ number_format($topUp['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="text-right label">Total Top-ups:</td>
                <td class="text-right value">KES {{ number_format($total_in, 2) }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    @if(count($disbursements) > 0)
    <div class="section-title">II. Disbursements (Outflow)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Receiver</th>
                <th>Classification</th>
                <th>Description</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($disbursements as $disb)
            <tr>
                <td>{{ \Carbon\Carbon::parse($disb['created_at'])->format('d/m/Y') }}</td>
                <td>{{ $disb['receiver'] }}</td>
                <td>{{ ucfirst($disb['classification']) }}</td>
                <td>{{ $disb['description'] }}</td>
                <td class="text-right">KES {{ number_format($disb['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="text-right label">Total Disbursements:</td>
                <td class="text-right value">KES {{ number_format($total_out, 2) }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    <div class="footer">
        <table class="signature-grid">
            <tr>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Prepared By</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Authorized By</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Audited By</div>
                </td>
            </tr>
        </table>
        
        <div class="timestamp">
            Generated by System on {{ date('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>
