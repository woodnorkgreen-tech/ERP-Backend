<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Purchase Order - {{ $po->po_number }}</title>
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
            color: #007bff;
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
            background-color: #007bff;
            color: white;
            text-align: left;
            padding: 8px;
            font-weight: bold;
        }
        table.data-table td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .text-right {
            text-align: right;
        }
        .total-section {
            float: right;
            width: 300px;
        }
        .total-row {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .grand-total {
            font-size: 14px;
            font-weight: bold;
            color: #007bff;
            border-bottom: 2px double #007bff;
            margin-top: 10px;
            padding: 5px 0;
        }
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
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
        .notes-section {
            margin-top: 20px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="header clearfix">
        <div style="float: left;">
            <img src="{{ public_path('logo-outline.png') }}" style="height: 60px; width: auto; margin-bottom: 5px; display: block;" alt="Logo"/>
            <div class="company-name">WOODNORK GREEN LTD</div>
            <div style="font-size: 12px; color: #555;">Designers & Manufacturers of Custom Furniture</div>
        </div>
        <div class="document-title">
            <h2>PURCHASE ORDER</h2>
            <div style="font-weight: bold; font-size: 14px;"># {{ $po->po_number }}</div>
            <div>Date: {{ \Carbon\Carbon::parse($po->date)->format('d M Y') }}</div>
        </div>
    </div>

    <table class="info-grid">
        <tr>
            <td class="info-box">
                <div class="section-title">Supplier Information</div>
                <div style="font-weight: bold; font-size: 13px; margin-bottom: 5px;">{{ $po->supplier->supplier_name }}</div>
                <div>{{ $po->supplier->email }}</div>
                <div>{{ $po->supplier->phone_number }}</div>
                <div>{{ $po->supplier->address }}</div>
            </td>
            <td style="width: 4%;"></td>
            <td class="info-box">
                <div class="section-title">Order Information</div>
                <p><span class="label">Due Date:</span> {{ \Carbon\Carbon::parse($po->due_date)->format('d M Y') }}</p>
                <p><span class="label">Delivery To:</span> {{ $po->delivery_address }}</p>
                <p><span class="label">Status:</span> {{ ucfirst($po->status) }}</p>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 45%;">Item Description</th>
                <th style="width: 15%;" class="text-right">Quantity</th>
                <th style="width: 15%;" class="text-right">Unit Price</th>
                <th style="width: 20%;" class="text-right">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->material->material_name }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">{{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="clearfix">
        <div class="notes-section" style="float: left; width: 60%;">
            <div style="font-weight: bold; margin-bottom: 5px;">Description / Notes:</div>
            <div>{{ $po->description ?: 'No additional notes.' }}</div>
        </div>
        <div class="total-section">
            <div class="total-row clearfix">
                <div style="float: left; font-weight: bold;">Subtotal</div>
                <div style="float: right;">KES {{ number_format($po->total_amount, 2) }}</div>
            </div>
            <div class="total-row clearfix">
                <div style="float: left; font-weight: bold;">Tax (0%)</div>
                <div style="float: right;">KES 0.00</div>
            </div>
            <div class="grand-total clearfix">
                <div style="float: left;">GRAND TOTAL</div>
                <div style="float: right;">KES {{ number_format($po->total_amount, 2) }}</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <table class="signature-grid">
            <tr>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Prepared By</div>
                    <div style="font-size: 10px; margin-top: 5px;">{{ $po->createdBy->name ?? 'System' }}</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Authorized By</div>
                    <div style="font-size: 10px; margin-top: 5px;">{{ $po->approvedBy->name ?? 'Pending Approval' }}</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Supplier Confirmation</div>
                </td>
            </tr>
        </table>
        
        <div style="font-size: 9px; color: #999; margin-top: 30px; text-align: right;">
            Generated by System on {{ date('d M Y H:i:s') }}
        </div>
    </div>
</body>
</html>
