<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Budget Report - {{ $enquiry->job_number ?? $enquiry->enquiry_number ?? $budgetData->project_info['job_number'] ?? $budgetData->project_info['projectId'] ?? 'Draft' }}</title>
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
        .text-cyan-500, .text-blue-600 { color: #07ADD4; }
        .text-red-600 { color: #dc2626; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }
        
        .bg-cyan-500, .bg-blue-600 { background-color: #07ADD4; color: white; }
        .bg-gray-200 { background-color: #e5e7eb; }
        .bg-gray-100 { background-color: #f3f4f6; }
        .bg-white { background-color: #ffffff; }
        
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        /* Layout Utilities */
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 15px; }
        
        .section-header {
            background-color: #07ADD4;
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
            background-color: #f3f4f6;
            padding: 8px;
            border: 1px solid #d1d5db;
            font-size: 10px;
            border-radius: 2px;
        }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 10px; }
        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            border-bottom: 2px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 8px;
        }
        .data-table td {
            border-bottom: 1px solid #f1f5f9;
            padding: 6px 8px;
        }
        
        .total-row {
            background-color: #f8fafc;
            font-weight: bold;
        }

        .summary-card {
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
        }

        .footer {
            margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb;
            text-align: center; color: #4b5563; font-size: 9px;
        }

        .category-title {
            font-size: 11px;
            font-weight: bold;
            color: #1e40af;
            border-left: 3px solid #07ADD4;
            padding-left: 8px;
            margin: 15px 0 10px 0;
            background-color: #eff6ff;
            padding-top: 4px;
            padding-bottom: 4px;
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
                <h2 class="text-blue-600 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">PROJECT BUDGET</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-blue-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">Job #</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">{{ $enquiry->job_number ?? $enquiry->enquiry_number ?? $budgetData->project_info['job_number'] ?? $budgetData->project_info['projectId'] ?? 'N/A' }}</td>
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
                        <div class="mb-2"><span class="font-bold">Project Title:</span> {{ $enquiry->title ?? $budgetData->project_info['project_title'] ?? $budgetData->project_info['enquiryTitle'] ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ $enquiry->client?->full_name ?? $enquiry->client_name ?? $budgetData->project_info['client_name'] ?? $budgetData->project_info['clientName'] ?? 'N/A' }}</div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Location:</span> {{ $enquiry->venue ?? $budgetData->project_info['location'] ?? $budgetData->project_info['eventVenue'] ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Status:</span> <span class="uppercase font-bold text-blue-600">{{ $budgetData->status ?? 'Draft' }}</span></div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Budget Summary Cards -->
    <div class="mb-4">
        <div class="section-header">BUDGET SUMMARY</div>
        <table style="width: 100%;">
            <tr>
                <td style="width: 24%; padding-right: 1%;">
                    <div class="info-box text-center">
                        <div class="text-gray-600 uppercase font-small mb-2">Materials</div>
                        <div class="text-lg font-bold">{{ number_format($budgetData->budget_summary['materials']['total'] ?? $budgetData->budget_summary['materialsTotal'] ?? 0, 2) }}</div>
                    </div>
                </td>
                <td style="width: 24%; padding-right: 1%;">
                    <div class="info-box text-center">
                        <div class="text-gray-600 uppercase font-small mb-2">Labour</div>
                        <div class="text-lg font-bold">{{ number_format($budgetData->budget_summary['labour']['total'] ?? $budgetData->budget_summary['labourTotal'] ?? 0, 2) }}</div>
                    </div>
                </td>
                <td style="width: 24%; padding-right: 1%;">
                    <div class="info-box text-center">
                        <div class="text-gray-600 uppercase font-small mb-2">Expenses</div>
                        <div class="text-lg font-bold">{{ number_format($budgetData->budget_summary['expenses']['total'] ?? $budgetData->budget_summary['expensesTotal'] ?? 0, 2) }}</div>
                    </div>
                </td>
                <td style="width: 24%;">
                    <div class="info-box text-center bg-blue-600" style="color: white; border-color: #1e40af;">
                        <div class="uppercase font-small mb-2">Grand Total</div>
                        <div class="text-lg font-bold">{{ number_format($budgetData->budget_summary['grandTotal'] ?? 0, 2) }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Fabrication / Materials Section -->
    @if(!empty($budgetData->materials_data))
    <div class="category-title">01. FABRICATION & MATERIALS</div>
    @foreach($budgetData->materials_data as $element)
        @if(!empty($element['materials']))
        <div style="font-weight: bold; background: #f8fafc; padding: 4px 8px; border: 1px solid #e2e8f0; border-bottom: none; font-size: 9px;">
            ELEMENT: {{ $element['name'] ?? 'Custom Element' }} ({{ $element['category'] ?? 'General' }})
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 45%;">Material Description</th>
                    <th style="width: 15%; text-align: center;">Qty</th>
                    <th style="width: 20%; text-align: right;">Unit Cost</th>
                    <th style="width: 20%; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($element['materials'] as $item)
                <tr>
                    <td>{{ $item['description'] ?? 'N/A' }}</td>
                    <td class="text-center">{{ $item['quantity'] ?? 0 }} {{ $item['unitOfMeasurement'] ?? '' }}</td>
                    <td class="text-right">{{ number_format($item['unitPrice'] ?? 0, 2) }}</td>
                    <td class="text-right font-bold">{{ number_format($item['totalPrice'] ?? (($item['quantity'] ?? 0) * ($item['unitPrice'] ?? 0)), 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    @endforeach
    <div style="text-align: right; padding: 10px; font-weight: bold; font-size: 10px; background: #f1f5f9;">
        SUBTOTAL MATERIALS: {{ number_format($budgetData->budget_summary['materials']['total'] ?? $budgetData->budget_summary['materialsTotal'] ?? 0, 2) }}
    </div>
    @endif

    <!-- Labour Section -->
    @if(!empty($budgetData->labour_data))
    <div class="category-title">02. LABOUR & SKILLED MANPOWER</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 40%;">Description</th>
                <th style="width: 10%; text-align: center;">Units</th>
                <th style="width: 15%; text-align: right;">Rate</th>
                <th style="width: 15%; text-align: right;">Total</th>
                <th style="width: 20%;">Type</th>
            </tr>
        </thead>
        <tbody>
            @foreach($budgetData->labour_data as $item)
            <tr>
                <td>{{ $item['description'] ?? 'N/A' }}</td>
                <td class="text-center">{{ $item['quantity'] ?? $item['units'] ?? 0 }}</td>
                <td class="text-right">{{ number_format($item['rate'] ?? $item['unitRate'] ?? $item['cost'] ?? 0, 2) }}</td>
                <td class="text-right font-bold">{{ number_format($item['totalPrice'] ?? (($item['quantity'] ?? $item['units'] ?? 0) * ($item['rate'] ?? $item['unitRate'] ?? $item['cost'] ?? 0)), 2) }}</td>
                <td class="uppercase">{{ $item['type'] ?? 'N/A' }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3" class="text-right uppercase">Subtotal Labour</td>
                <td class="text-right">{{ number_format($budgetData->budget_summary['labour']['total'] ?? $budgetData->budget_summary['labourTotal'] ?? 0, 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    @endif

    <!-- Operational Expenses Section -->
    @if(!empty($budgetData->expenses_data))
    <div class="category-title">03. OPERATIONAL EXPENSES</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 40%;">Expense Detail</th>
                <th style="width: 15%; text-align: right;">Amount</th>
                <th style="width: 45%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($budgetData->expenses_data as $item)
            <tr>
                <td>{{ $item['description'] ?? 'N/A' }}</td>
                <td class="text-right font-bold">{{ number_format($item['amount'] ?? $item['cost'] ?? $item['totalPrice'] ?? 0, 2) }}</td>
                <td>{{ $item['notes'] ?? '-' }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td class="text-right uppercase">Subtotal Expenses</td>
                <td class="text-right">{{ number_format($budgetData->budget_summary['expenses']['total'] ?? $budgetData->budget_summary['expensesTotal'] ?? 0, 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    @endif

    <!-- Logistics Section -->
    @if(!empty($budgetData->logistics_data))
    <div class="category-title">04. LOGISTICS & TRANSPORT</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 40%;">Route / Description</th>
                <th style="width: 15%; text-align: right;">Cost</th>
                <th style="width: 45%;">Vehicle Info / Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($budgetData->logistics_data as $item)
            <tr>
                <td>{{ $item['route'] ?? $item['description'] ?? 'N/A' }}</td>
                <td class="text-right font-bold">{{ number_format($item['cost'] ?? $item['totalPrice'] ?? 0, 2) }}</td>
                <td>{{ $item['vehicle_type'] ?? $item['vehicleType'] ?? '' }} {{ $item['notes'] ?? '' }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td class="text-right uppercase">Subtotal Logistics</td>
                <td class="text-right">{{ number_format($budgetData->budget_summary['logistics']['total'] ?? $budgetData->budget_summary['logisticsTotal'] ?? 0, 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    @endif

    <!-- Budget Notes -->
    @if(!empty($budgetData->project_info['notes']))
    <div class="mb-4" style="page-break-inside: avoid;">
        <div class="section-header">ADDITIONAL NOTES</div>
        <div class="info-box">
            {{ $budgetData->project_info['notes'] }}
        </div>
    </div>
    @endif


    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd</p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Physical Address: Karen Village, Ngong Road, Nairobi, Kenya | Website: www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
