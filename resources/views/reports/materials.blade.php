<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Materials Specification - {{ $enquiry->job_number ?? $enquiry->enquiry_number ?? $materialsData->project_info['projectId'] ?? 'Draft' }}</title>
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
        .text-cyan-500 { color: #06b6d4; }
        .text-blue-600 { color: #2563eb; }
        .text-red-600 { color: #dc2626; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }
        
        .bg-cyan-500 { background-color: #06b6d4; color: white; }
        .bg-blue-600 { background-color: #2563eb; color: white; }
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
            background-color: #2563eb;
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
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 15px; }
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
        
        .element-header {
            font-size: 11px;
            font-weight: bold;
            color: #1e40af;
            border-left: 3px solid #3b82f6;
            padding-left: 8px;
            margin: 20px 0 10px 0;
            background-color: #eff6ff;
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .footer {
            margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb;
            text-align: center; color: #4b5563; font-size: 9px;
        }

        .status-badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-approved { background-color: #dcfce7; color: #166534; }
        .status-pending { background-color: #fef9c3; color: #854d0e; }
    </style>
</head>
<body>

    <!-- Header -->
    <table style="margin-bottom: 20px;">
        <tr>
            <td style="width: 50%;">
                <img src="{{ public_path('logo-outline.png') }}" style="height: 65px; width: auto; margin-bottom: 5px; display: block;" alt="Logo"/>
                <div class="font-bold text-gray-900 tracking-wide uppercase" style="font-size: 14px;">Woodnork Green</div>
            </td>
            <td style="width: 50%; text-align: right;">
                <h2 class="text-blue-600 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">MATERIALS SPECIFICATION</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-blue-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">Job #</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">{{ $enquiry->job_number ?? $enquiry->enquiry_number ?? $materialsData->project_info['projectId'] ?? 'N/A' }}</td>
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
                        <div class="mb-2"><span class="font-bold">Project Title:</span> {{ $enquiry->title ?? $materialsData->project_info['enquiryTitle'] ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ $enquiry->client?->full_name ?? $enquiry->client_name ?? $materialsData->project_info['clientName'] ?? 'N/A' }}</div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Location:</span> {{ $enquiry->venue ?? $materialsData->project_info['eventVenue'] ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Setup Date:</span> {{ $enquiry->expected_delivery_date ?? $materialsData->project_info['setupDate'] ?? 'N/A' }}</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Approval Status -->
    <div class="mb-4">
        <div class="section-header" style="width: 40%;">APPROVAL STATUS</div>
        <div class="info-box">
            <table>
                <tr>
                    <td style="width: 50%;">
                        <div class="mb-2">
                            <span class="font-bold">Project Officer:</span>
                            @if($materialsData->project_info['approval_status']['project_officer']['approved'] ?? false)
                                <span class="status-badge status-approved">Approved</span>
                                <span style="font-size: 8px; color: #6b7280;">by {{ $materialsData->project_info['approval_status']['project_officer']['approved_by_name'] }} on {{ \Carbon\Carbon::parse($materialsData->project_info['approval_status']['project_officer']['approved_at'])->format('d/m/Y') }}</span>
                            @else
                                <span class="status-badge status-pending">Pending</span>
                            @endif
                        </div>
                    </td>
                    <td style="width: 50%;">
                        <div class="mb-2">
                            <span class="font-bold">Production:</span>
                            @if($materialsData->project_info['approval_status']['production']['approved'] ?? false)
                                <span class="status-badge status-approved">Approved</span>
                                <span style="font-size: 8px; color: #6b7280;">by {{ $materialsData->project_info['approval_status']['production']['approved_by_name'] }} on {{ \Carbon\Carbon::parse($materialsData->project_info['approval_status']['production']['approved_at'])->format('d/m/Y') }}</span>
                            @else
                                <span class="status-badge status-pending">Pending</span>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Elements & Materials -->
    @foreach($materialsData->elements as $element)
        @if($element->is_included)
            <div class="element-header">
                {{ strtoupper($element->name) }} 
                <span style="font-size: 9px; font-weight: normal; color: #4b5563;">({{ strtoupper($element->category) }})</span>
            </div>
            
            @if(!empty($element->materials))
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 50%;">Description</th>
                            <th style="width: 25%; text-align: center;">Standard Unit</th>
                            <th style="width: 25%; text-align: center;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($element->materials as $material)
                            @if($material->is_included)
                                <tr>
                                    <td>{{ $material->description }} @if($material->is_additional) <span style="color: #2563eb; font-size: 7px;">(Additional)</span> @endif</td>
                                    <td class="text-center">{{ $material->unit_of_measurement }}</td>
                                    <td class="text-center font-bold">{{ $material->quantity }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="font-style: italic; color: #6b7280; padding-left: 10px;">No materials specified for this element.</p>
            @endif

            @if(!empty($element->notes))
                <div style="margin-top: -10px; margin-bottom: 20px; padding: 5px 10px; background-color: #f9fafb; border: 1px dashed #d1d5db; font-size: 8px;">
                    <span class="font-bold">Element Notes:</span> {{ $element->notes }}
                </div>
            @endif
        @endif
    @endforeach

    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd</p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Karen Village, Ngong Road, Nairobi, Kenya | www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
