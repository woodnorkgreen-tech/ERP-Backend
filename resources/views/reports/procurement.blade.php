<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Procurement List - {{ $enquiry->job_number ?? $enquiry->enquiry_number ?? $procurementData->project_info['projectId'] ?? 'Draft' }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111827;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        @page {
            margin: 0.5in;
        }
        
        /* Typography & Colors */
        .text-blue-600 { color: #07ADD4; }
        .text-red-600 { color: #dc2626; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }
        .text-emerald-600 { color: #059669; }
        
        .bg-blue-600 { background-color: #07ADD4; color: white; }
        .bg-gray-100 { background-color: #f3f4f6; }
        
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        /* Layout Utilities */
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 15px; }
        
        .section-header {
            background-color: #1e293b;
            color: white;
            padding: 4px 8px;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
            display: inline-block;
            width: 100%;
        }

        .info-box {
            background-color: #f8fafc;
            padding: 8px;
            border: 1px solid #e2e8f0;
            font-size: 10px;
            border-radius: 2px;
        }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 15px; }
        .data-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: bold;
            text-align: left;
            padding: 8px;
            border-bottom: 2px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 8px;
        }
        .data-table td {
            border-bottom: 1px solid #f1f5f9;
            padding: 8px;
        }
        
        .status-badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            display: inline-block;
        }
        
        /* Stock Status Colors */
        .stock-in_stock { background-color: #dcfce7; color: #166534; }
        .stock-partial_stock { background-color: #fef9c3; color: #854d0e; }
        .stock-out_of_stock { background-color: #fee2e2; color: #991b1b; }
        .stock-pending_check { background-color: #f1f5f9; color: #475569; }

        /* Procurement Status Colors */
        .proc-ordered { background-color: #dbeafe; color: #1e40af; }
        .proc-received { background-color: #dcfce7; color: #166534; }
        .proc-pending { background-color: #fff7ed; color: #9a3412; }
        .proc-not_needed { background-color: #f1f5f9; color: #475569; }

        .footer {
            margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb;
            text-align: center; color: #4b5563; font-size: 9px;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <table style="margin-bottom: 20px;">
        <tr>
            <td style="width: 50%;">
                <img src="{{ public_path('woodnork-green-logo.png') }}" style="width: 125px; height: auto; margin-bottom: 5px; display: block;" alt="Woodnork Green logo"/>
            </td>
            <td style="width: 50%; text-align: right;">
                <h2 class="text-blue-600 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">PROCUREMENT LIST</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-blue-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">Job #</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">{{ $enquiry->job_number ?? $enquiry->enquiry_number ?? $procurementData->project_info['projectId'] ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Project Details -->
    <div class="mb-4">
        <div class="section-header" style="width: 40%;">PROJECT INFORMATION</div>
        <div class="info-box">
            <table>
                <tr>
                    <td style="width: 60%;">
                        <div class="mb-2"><span class="font-bold">Project Title:</span> {{ $enquiry->title ?? $procurementData->project_info['enquiryTitle'] ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ $enquiry->client?->full_name ?? $procurementData->project_info['clientName'] ?? 'N/A' }}</div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Location:</span> {{ $enquiry->venue ?? $procurementData->project_info['eventVenue'] ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Setup Date:</span> {{ $enquiry->expected_delivery_date ?? $procurementData->project_info['setupDate'] ?? 'N/A' }}</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Procurement Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 20%;">Material / Element</th>
                <th style="width: 8%; text-align: center;">Qty</th>
                <th style="width: 15%; text-align: center;">Stock Status</th>
                <th style="width: 15%; text-align: center;">Procurement</th>
                <th style="width: 10%; text-align: center;">Purch. Qty</th>
                <th style="width: 15%;">Vendor / PO</th>
                <th style="width: 17%;">Expected Delivery</th>
            </tr>
        </thead>
        <tbody>
            @foreach($procurementData->procurement_items as $item)
                <tr>
                    <td>
                        <div class="font-bold">{{ $item['description'] ?? 'No Description' }}</div>
                        <div style="font-size: 7px; color: #64748b;">{{ $item['elementName'] ?? 'N/A' }}</div>
                    </td>
                    <td class="text-center font-bold">{{ $item['quantity'] ?? 0 }} {{ $item['unitOfMeasurement'] ?? '' }}</td>
                    <td class="text-center">
                        @php $stockStatus = $item['stockStatus'] ?? 'pending_check'; @endphp
                        <span class="status-badge stock-{{ $stockStatus }}">
                            {{ str_replace('_', ' ', $stockStatus) }}
                        </span>
                        @if(isset($item['stockQuantity']) && $item['stockQuantity'] > 0)
                            <div style="font-size: 7px; margin-top: 2px;">Qty: {{ $item['stockQuantity'] }}</div>
                        @endif
                    </td>
                    <td class="text-center">
                        @php $procStatus = $item['procurementStatus'] ?? 'not_needed'; @endphp
                        <span class="status-badge proc-{{ $procStatus }}">
                            {{ str_replace('_', ' ', $procStatus) }}
                        </span>
                    </td>
                    <td class="text-center font-bold">
                        {{ $item['purchaseQuantity'] ?? 0 }}
                    </td>
                    <td>
                        @if(!empty($item['vendorName']))
                            <div class="font-bold">{{ $item['vendorName'] }}</div>
                        @endif
                        @if(!empty($item['purchaseOrderNumber']))
                            <div style="font-size: 7px; color: #1e40af;">PO: {{ $item['purchaseOrderNumber'] }}</div>
                        @endif
                    </td>
                    <td>
                        @if(!empty($item['expectedDeliveryDate']))
                            {{ \Carbon\Carbon::parse($item['expectedDeliveryDate'])->format('d/m/Y') }}
                        @else
                            -
                        @endif
                        @if(!empty($item['procurementNotes']))
                            <div style="font-size: 7px; color: #64748b; font-style: italic; margin-top: 2px;">
                                {{ $item['procurementNotes'] }}
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd</p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Karen Village, Ngong Road, Nairobi, Kenya | www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
