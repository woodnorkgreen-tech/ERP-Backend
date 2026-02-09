<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Bill - {{ $bill->bill_number }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.4;
        }
        .header {
            border-bottom: 2px solid #444;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 18px;
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
            color: #6f42c1;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-box {
            width: 48%;
            vertical-align: top;
        }
        .section-title {
            font-size: 12px;
            font-weight: bold;
            background-color: #eee;
            padding: 5px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .label {
            font-weight: bold;
            color: #666;
            width: 100px;
            display: inline-block;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.data-table th {
            background-color: #6f42c1;
            color: white;
            text-align: left;
            padding: 8px;
            font-weight: bold;
        }
        table.data-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer {
            margin-top: 50px;
            border-top: 1px solid #ddd;
            padding-top: 20px;
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
        .total-summary {
            float: right;
            width: 250px;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .grand-total {
            font-size: 14px;
            font-weight: bold;
            color: #6f42c1;
            border-top: 1px solid #dee2e6;
            padding-top: 10px;
            margin-top: 10px;
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
            <img src="{{ public_path('logo-outline.png') }}" style="height: 60px; width: auto; margin-bottom: 5px; display: block;" alt="Logo"/>
            <div class="company-name">WOODNORK GREEN LTD</div>
            <div style="font-size: 12px; color: #555;">Purchasing & Accounts</div>
        </div>
        <div class="document-title">
            <h2>BILL / INVOICE</h2>
            <div style="font-weight: bold; font-size: 14px;"># {{ $bill->bill_number }}</div>
            <div>Date: {{ \Carbon\Carbon::parse($bill->bill_date)->format('d M Y') }}</div>
        </div>
    </div>

    <table class="info-grid">
        <tr>
            <td class="info-box">
                <div class="section-title">Supplier Information</div>
                <div style="font-weight: bold; font-size: 13px;">{{ $bill->supplier->supplier_name }}</div>
                <div>{{ $bill->supplier->phone_number }}</div>
                <div>PO Ref: {{ $bill->purchaseOrder->po_number }}</div>
            </td>
            <td style="width: 4%;"></td>
            <td class="info-box">
                <div class="section-title">Payment Terms</div>
                <p><span class="label">Due Date:</span> {{ \Carbon\Carbon::parse($bill->due_date)->format('d M Y') }}</p>
                <p><span class="label">Status:</span> {{ ucfirst($bill->status) }}</p>
                <p><span class="label">Balanced Owed:</span> KES {{ number_format($bill->balance, 2) }}</p>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 10%;">Date</th>
                <th style="width: 60%;">Description / Reference</th>
                <th style="width: 30%;" class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ \Carbon\Carbon::parse($bill->bill_date)->format('d/m/Y') }}</td>
                <td>Bill for Purchase Order #{{ $bill->purchaseOrder->po_number }}</td>
                <td class="text-right">{{ number_format($bill->amount, 2) }}</td>
            </tr>
            
            @if($bill->payments && count($bill->payments) > 0)
            <tr>
                <td colspan="3" style="font-weight: bold; background-color: #f0f0f0; padding: 5px;">Payment History</td>
            </tr>
            @foreach($bill->payments as $payment)
            <tr>
                <td>{{ \Carbon\Carbon::parse($payment->payment_date)->format('d/m/Y') }}</td>
                <td>Payment Ref: {{ $payment->reference_number }} ({{ $payment->payment_code }}) - via {{ $payment->paymentMethod->method_name ?? 'N/A' }}</td>
                <td class="text-right" style="color: green;">- {{ number_format($payment->amount_paid, 2) }}</td>
            </tr>
            @endforeach
            @endif
        </tbody>
    </table>

    <div class="clearfix">
        <div class="total-summary">
            <table style="width: 100%;">
                <tr>
                    <td style="font-weight: bold;">Total Bill:</td>
                    <td class="text-right">KES {{ number_format($bill->amount, 2) }}</td>
                </tr>
                <tr>
                    <td style="font-weight: bold;">Total Paid:</td>
                    <td class="text-right" style="color: green;">KES {{ number_format($bill->paid_amount, 2) }}</td>
                </tr>
                <tr class="grand-total">
                    <td style="font-weight: bold;">BALANCE DUE:</td>
                    <td class="text-right">KES {{ number_format($bill->balance, 2) }}</td>
                </tr>
            </table>
        </div>
    </div>

    @if($bill->notes)
    <div style="margin-top: 20px; padding: 10px; background-color: #f9f9f9; border-radius: 5px;">
        <div style="font-weight: bold; margin-bottom: 5px;">Notes:</div>
        <div>{{ $bill->notes }}</div>
    </div>
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
                    <div class="signature-label">Accounts Approval</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Audit Control</div>
                </td>
            </tr>
        </table>
        
        <div style="font-size: 9px; color: #999; margin-top: 30px; text-align: right;">
            Generated by System on {{ date('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>
