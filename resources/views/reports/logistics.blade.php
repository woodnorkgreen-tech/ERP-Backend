<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Logistics Report - {{ optional($project)->project_id ?? 'Draft' }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111827;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        @page { margin: 0.5in; }
        
        .text-green-500 { color: #10b981; }
        .text-red-600 { color: #dc2626; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }
        
        .bg-green-500 { background-color: #10b981; color: white; }
        .bg-gray-200 { background-color: #e5e7eb; }
        .bg-gray-100 { background-color: #f3f4f6; }
        .bg-white { background-color: #ffffff; }
        
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .font-small { font-size: 9px; }
        
        .mb-2 { margin-bottom: 5px; }
        .mb-4 { margin-bottom: 15px; }
        
        .section-header {
            background-color: #10b981;
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

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .data-table th {
            background-color: #10b981;
            color: white;
            font-weight: bold;
            text-align: left;
            padding: 5px;
            border: 1px solid white;
        }
        .data-table td {
            border: 1px solid #d1d5db;
            padding: 5px;
        }
        
        .checklist-item { margin-bottom: 4px; }
        .checkbox { 
            display: inline-block; width: 10px; height: 10px; border: 1px solid #6b7280; 
            margin-right: 5px; vertical-align: middle; position: relative;
        }
        .checkbox.checked { background-color: #10b981; border-color: #10b981; }
        .checkbox.checked:after { content: '✓'; color: white; font-size: 8px; position: absolute; top: -2px; left: 1px; }

        .signature-box {
            border: 1px solid #d1d5db; background-color: #f9fafb; height: 60px;
            text-align: center; margin-top: 5px;
        }
        
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
                <img src="{{ 'file:///' . str_replace('\\', '/', base_path('frontend/src/assets/WNG-Logo.png')) }}" style="height: 50px; width: auto; margin-bottom: 5px;" alt="Logo"/>
                <div class="font-bold text-gray-900 tracking-wide uppercase" style="font-size: 14px;">Woodnork Green</div>
            </td>
            <td style="width: 50%; text-align: right;">
                <h2 class="text-green-500 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">LOGISTICS REPORT</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">Ref ID</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">#{{ $data['task_id'] }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Project Details -->
    <div class="mb-4">
        <div class="section-header" style="width: 40%;">PROJECT DETAILS</div>
        <div class="info-box">
            <table>
                <tr>
                    <td style="width: 60%;">
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ optional($client)->name ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Project Name:</span> {{ $task->enquiry->title ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Project Code:</span> <span class="text-red-600 font-bold">{{ optional($project)->project_id ?? 'N/A' }}</span></div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Destination:</span> {{ $data['logistics_planning']['route']['destination'] ?? 'TBC' }}</div>
                         <div class="mb-2"><span class="font-bold">Travel Date:</span> 
                             @if(isset($data['logistics_planning']['timeline']['departure_time']))
                                {{ \Carbon\Carbon::parse($data['logistics_planning']['timeline']['departure_time'])->format('d/m/Y') }}
                             @else
                                TBC
                             @endif
                         </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Logistics Plan -->
    <div class="mb-4">
        <table>
            <tr>
                <td style="width: 32%; padding-right: 1.3%;">
                    <div class="section-header">TRANSPORT</div>
                    <div class="info-box" style="height: 80px;">
                        <div class="mb-2"><span class="font-bold">Vehicle:</span> {{ $data['logistics_planning']['vehicle_type'] ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Reg #:</span> {{ $data['logistics_planning']['vehicle_identification'] ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Driver:</span> {{ $data['logistics_planning']['driver_name'] ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Contact:</span> {{ $data['logistics_planning']['driver_contact'] ?? 'N/A' }}</div>
                    </div>
                </td>
                <td style="width: 32%; padding-right: 1.3%;">
                    <div class="section-header">ROUTE</div>
                    <div class="info-box" style="height: 80px;">
                         <div class="mb-2"><span class="font-bold">Origin:</span> {{ $data['logistics_planning']['route']['origin'] ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">To:</span> {{ $data['logistics_planning']['route']['destination'] ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Dist/Time:</span> {{ $data['logistics_planning']['route']['distance'] ?? '-' }} KM / {{ $data['logistics_planning']['route']['travel_time'] ?? '-' }}</div>
                    </div>
                </td>
                <td style="width: 32%;">
                    <div class="section-header">TIMELINE</div>
                    <div class="info-box" style="height: 80px;">
                         <div class="mb-2"><span class="font-bold">Depart:</span> {{ isset($data['logistics_planning']['timeline']['departure_time']) ? \Carbon\Carbon::parse($data['logistics_planning']['timeline']['departure_time'])->format('H:i') : '--:--' }}</div>
                         <div class="mb-2"><span class="font-bold">Arrive:</span> {{ isset($data['logistics_planning']['timeline']['arrival_time']) ? \Carbon\Carbon::parse($data['logistics_planning']['timeline']['arrival_time'])->format('H:i') : '--:--' }}</div>
                         <div class="mb-2"><span class="font-bold">Setup Start:</span> {{ isset($data['logistics_planning']['timeline']['setup_start_time']) ? \Carbon\Carbon::parse($data['logistics_planning']['timeline']['setup_start_time'])->format('d/m H:i') : '--:--' }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Transport Items -->
    <div class="mb-4">
        <div class="section-header">CARGO MANIFEST & TRANSPORT ITEMS</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="35%">Item Name</th>
                    <th width="10%">Qty</th>
                    <th width="10%">Unit</th>
                    <th width="20%">Category</th>
                    <th width="25%">Handling / Notes</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['transport_items'] as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td class="text-center font-bold">{{ $item['quantity'] }}</td>
                    <td class="text-center">{{ $item['unit'] }}</td>
                    <td>{{ $item['main_category'] ?? $item['category'] }}</td>
                    <td><span class="font-small text-gray-600">{{ $item['special_handling'] ?? $item['description'] ?? '-' }}</span></td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-gray-600">No items listed.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Checklist & Safety -->
    <div class="mb-4">
        <table>
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <div class="section-header">SAFETY & EQUIPMENT</div>
                    <div class="info-box mb-2">
                        <div class="font-bold mb-1 border-b border-gray-300">SAFETY GEAR</div>
                        <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['safety']['ppe'] ?? false) ? 'checked' : '' }}"></span> PPE (Vests, Helmets, Boots)</div>
                        <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['safety']['first_aid'] ?? false) ? 'checked' : '' }}"></span> First Aid Kit</div>
                        <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['safety']['fire_extinguisher'] ?? false) ? 'checked' : '' }}"></span> Fire Extinguisher</div>
                    </div>
                    <div class="info-box">
                        <div class="font-bold mb-1 border-b border-gray-300">EQUIPMENT</div>
                         <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['equipment']['tools'] ?? false) ? 'checked' : '' }}"></span> Tool Kits</div>
                         <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['equipment']['vehicles'] ?? false) ? 'checked' : '' }}"></span> Vehicle Inspection</div>
                         <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['equipment']['communication'] ?? false) ? 'checked' : '' }}"></span> Comms (Radios/Phones)</div>
                    </div>
                </td>
                <td style="width: 48%; padding-left: 2%;">
                     <div class="section-header">LOADING CHECKLIST</div>
                     @if(isset($data['checklist']['items']) && count($data['checklist']['items']) > 0)
                     <table class="data-table">
                         <thead>
                             <tr>
                                 <th>Item Checks</th>
                                 <th>Status</th>
                             </tr>
                         </thead>
                         <tbody>
                             @foreach(array_slice($data['checklist']['items'], 0, 10) as $checkItem)
                             <tr>
                                 <td>{{ $checkItem['item_name'] }}</td>
                                 <td class="uppercase font-bold {{ $checkItem['status'] == 'present' ? 'text-green-500' : 'text-red-600' }}">
                                     {{ $checkItem['status'] }}
                                 </td>
                             </tr>
                             @endforeach
                              @if(count($data['checklist']['items']) > 10)
                                 <tr><td colspan="2" class="text-center font-small text-gray-600">... and {{ count($data['checklist']['items']) - 10 }} more items</td></tr>
                             @endif
                         </tbody>
                     </table>
                     @else
                        <div class="info-box text-center">No specific checklist items generated.</div>
                     @endif
                </td>
            </tr>
        </table>
    </div>

    <!-- Signatures -->
    <div style="page-break-inside: avoid; margin-top: 20px;">
        <div class="section-header">DISPATCH APPROVALS</div>
        <table>
            <tr>
                <td style="width: 30%; padding-right: 3%;">
                     <div class="font-bold text-center mb-1 text-gray-600">DRIVER</div>
                     <div class="signature-box"></div>
                     <div class="text-center mt-1 font-bold">{{ $data['logistics_planning']['driver_name'] ?? 'Driver Name' }}</div>
                     <div class="text-center text-gray-600 font-small">Date: _______</div>
                </td>
                <td style="width: 30%; padding-right: 3%;">
                     <div class="font-bold text-center mb-1 text-gray-600">LOGISTICS MANAGER</div>
                     <div class="signature-box"></div>
                     <div class="text-center mt-1 font-bold">Approved By</div>
                     <div class="text-center text-gray-600 font-small">Date: _______</div>
                </td>
                 <td style="width: 30%;">
                     <div class="font-bold text-center mb-1 text-gray-600">SECURITY / GATE</div>
                     <div class="signature-box"></div>
                     <div class="text-center mt-1 font-bold">Security Check</div>
                     <div class="text-center text-gray-600 font-small">Date: _______</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd</p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Physical Address: Karen Village, Ngong Road, Nairobi, Kenya | Website: www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
