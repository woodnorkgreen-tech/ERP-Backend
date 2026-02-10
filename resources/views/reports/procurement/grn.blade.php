<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Goods Receipt Note - {{ $grn->grn_number }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.3;
        }
        .header {
            border-bottom: 2px solid #444;
            padding-bottom: 5px;
            margin-bottom: 15px;
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
            color: #28a745;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 15px;
        }
        .info-box {
            width: 48%;
            vertical-align: top;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            background-color: #f0f0f0;
            padding: 5px;
            margin-bottom: 8px;
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
            margin-bottom: 15px;
        }
        table.data-table th {
            background-color: #28a745;
            color: white;
            text-align: left;
            padding: 6px;
            font-weight: bold;
        }
        table.data-table td {
            padding: 6px;
            border-bottom: 1px solid #eee;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .footer {
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
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
        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-success { background-color: #d4edda; color: #155724; }
        .badge-danger { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header clearfix">
        <div style="float: left;">
            <img src="{{ public_path('logo-outline.png') }}" style="height: 50px; width: auto; margin-bottom: 5px; display: block;" alt="Logo"/>
            <div class="company-name">WOODNORK GREEN LTD</div>
            <div style="font-size: 10px; color: #555;">Goods Receipt Note (GRN)</div>
        </div>
        <div class="document-title">
            <h2>GOODS RECEIPT</h2>
            <div style="font-weight: bold; font-size: 14px;"># {{ $grn->grn_number }}</div>
            <div>Date: {{ \Carbon\Carbon::parse($grn->date)->format('d M Y') }}</div>
        </div>
    </div>

    <table class="info-grid">
        <tr>
            <td class="info-box">
                <div class="section-title">Supplier Information</div>
                <div style="font-weight: bold; font-size: 12px;">{{ $grn->purchaseOrder->supplier->supplier_name }}</div>
                <div>PO Reference: {{ $grn->purchaseOrder->po_number }}</div>
                <div>Batch Number: {{ $grn->batch_number }}</div>
            </td>
            <td style="width: 4%;"></td>
            <td class="info-box">
                <div class="section-title">Receipt Information</div>
                <p><span class="label">Received By:</span> {{ $grn->receivedByUser->name ?? 'N/A' }}</p>
                <p><span class="label">Location:</span> {{ $grn->store_location }}</p>
                <p><span class="label">Quality Check:</span> 
                    <span class="badge {{ $grn->quality_check == 'pass' ? 'badge-success' : 'badge-danger' }}">
                        {{ $grn->quality_check }}
                    </span>
                </p>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 15%;">Code</th>
                <th style="width: 35%;">Material Name</th>
                <th style="width: 15%;" class="text-center">Ordered</th>
                <th style="width: 15%;" class="text-center">Received</th>
                <th style="width: 15%;" class="text-center">Condition</th>
            </tr>
        </thead>
        <tbody>
            @foreach($grn->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->material->material_code ?? 'N/A' }}</td>
                <td>{{ $item->material->material_name ?? 'N/A' }}</td>
                <td class="text-center">{{ $item->ordered_quantity }}</td>
                <td class="text-center">{{ $item->received_quantity }}</td>
                <td class="text-center capitalize">{{ str_replace('_', ' ', $item->condition) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($grn->notes)
    <div style="margin-top: 10px; padding: 10px; background-color: #f9f9f9; border-radius: 4px;">
        <div style="font-weight: bold; margin-bottom: 5px;">General Notes:</div>
        <div>{{ $grn->notes }}</div>
    </div>
    @endif

    <div class="footer">
        <table class="signature-grid">
            <tr>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Received By (Store)</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Verified By (Admin)</div>
                </td>
                <td style="width: 5%;"></td>
                <td class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Supplier/Driver</div>
                </td>
            </tr>
        </table>
        
        <div style="font-size: 8px; color: #999; margin-top: 20px; text-align: right;">
            Generated by System on {{ date('d M Y H:i:s') }} | GRN Number: {{ $grn->grn_number }}
        </div>
    </div>
</body>
</html>
