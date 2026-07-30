<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Logistics Report - {{ optional($task->enquiry)->job_number ?? $task->enquiry->enquiry_number ?? $data['task_id'] }}</title>
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
        .text-emerald-600 { color: #059669; }
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

        /* Checklist Styles */
        .checklist-item { margin-bottom: 4px; }
        .checkbox { 
            display: inline-block; width: 10px; height: 10px; border: 1px solid #6b7280; 
            margin-right: 5px; vertical-align: middle; position: relative;
        }
        .checkbox.checked { background-color: #07ADD4; border-color: #07ADD4; }
        .checkbox.checked:after { content: '✓'; color: white; font-size: 8px; position: absolute; top: -2px; left: 1px; }

        .signature-box {
            border: 1px solid #d1d5db; background-color: #f9fafb; height: 60px;
            text-align: center; margin-top: 5px;
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
                <h2 class="text-blue-600 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">LOGISTICS LOADING SHEET</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-blue-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">Job #</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">{{ $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? $data['task_id'] }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Project Information -->
    <div class="mb-4">
        <div class="section-header" style="width: 40%;">PROJECT INFORMATION</div>
        <div class="info-box">
            <table>
                <tr>
                    <td style="width: 60%;">
                        <div class="mb-2"><span class="font-bold">Project Title:</span> {{ $task->enquiry->title ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ $client->full_name ?? $client->name ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Project Officer:</span> {{ $data['project_officer']['name'] ?? 'Unassigned' }}</div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Destination:</span> {{ $data['logistics_planning']['route']['destination'] ?? 'TBC' }}</div>
                         <div class="mb-2"><span class="font-bold">Prepared By:</span> {{ $data['logistics_planning']['prepared_by'] ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Status:</span> <span class="uppercase font-bold text-blue-600">{{ $task->status ?? 'In Progress' }}</span></div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Logistics & Dispatch Planning -->
    <div class="mb-4">
        <div class="section-header">DISPATCH & ROUTE PLANNING</div>
        @if(($data['logistics_planning']['transport_arrangement'] ?? 'company') === 'client')
        <div class="summary-card">
            <div class="text-gray-600 uppercase font-small mb-2">TRANSPORT ARRANGEMENT</div>
            <div class="font-bold">Client-provided transport</div>
            <div class="mt-1">Vehicle, driver and trip timing are arranged by the client. This document confirms the items released.</div>
        </div>
        @else
        <table style="width: 100%;">
            <tr>
                <td style="width: 33%; padding-right: 1%;">
                    <div class="summary-card">
                         <div class="text-gray-600 uppercase font-small mb-2">TRANSPORT</div>
                         <div class="mb-1"><span class="font-bold">Vehicle:</span> {{ $data['logistics_planning']['vehicle_type'] ?? 'N/A' }}</div>
                         <div class="mb-1"><span class="font-bold">Reg #:</span> {{ $data['logistics_planning']['vehicle_identification'] ?? 'N/A' }}</div>
                         <div class="mb-1"><span class="font-bold">Crew Vehicle:</span> {{ $data['logistics_planning']['crew_vehicle'] ?? '-' }}</div>
                         <div class="mb-1"><span class="font-bold">Driver:</span> {{ $data['logistics_planning']['driver_name'] ?? 'N/A' }}</div>
                         <div class="mb-1"><span class="font-bold">Team Captain:</span> {{ $data['logistics_planning']['team_captain'] ?? 'N/A' }}</div>
                    </div>
                </td>
                <td style="width: 33%; padding-right: 1%;">
                    <div class="summary-card">
                         <div class="text-gray-600 uppercase font-small mb-2">ROUTE</div>
                         <div class="mb-1"><span class="font-bold">From:</span> {{ $data['logistics_planning']['route']['origin'] ?? 'N/A' }}</div>
                         <div class="mb-1"><span class="font-bold">To:</span> {{ $data['logistics_planning']['route']['destination'] ?? 'N/A' }}</div>
                         <div class="mb-1"><span class="font-bold">Dist:</span> {{ $data['logistics_planning']['route']['distance'] ?? '-' }} KM</div>
                    </div>
                </td>
                <td style="width: 33%;">
                    <div class="summary-card bg-gray-100">
                         <div class="text-gray-600 uppercase font-small mb-2">TIMELINE</div>
                         <div class="mb-1"><span class="font-bold">Loading:</span> {{ $data['logistics_planning']['timeline']['loading_time'] ?? '--:--' }}</div>
                         <div class="mb-1"><span class="font-bold">Departure:</span> {{ $data['logistics_planning']['timeline']['departure_time'] ?? '--:--' }}</div>
                         <div class="mb-1"><span class="font-bold">Expected Arr:</span> {{ !empty($data['logistics_planning']['timeline']['setup_start_time']) ? \Carbon\Carbon::parse($data['logistics_planning']['timeline']['setup_start_time'])->format('d/m/Y') : 'TBC' }}</div>
                         <div class="mb-1"><span class="font-bold">Setup Time:</span> {{ $data['logistics_planning']['timeline']['setup_start_hour'] ?? '--:--' }} ({{ $data['logistics_planning']['timeline']['setup_duration'] ?? '-' }})</div>
                         <div class="mb-1"><span class="font-bold">Setdown:</span> {{ !empty($data['logistics_planning']['timeline']['setdown_date']) ? \Carbon\Carbon::parse($data['logistics_planning']['timeline']['setdown_date'])->format('d/m/Y') : 'TBC' }} {{ $data['logistics_planning']['timeline']['setdown_time'] ?? '' }}</div>
                    </div>
                </td>
            </tr>
        </table>
        @endif
    </div>

    <!-- Project Teams -->
    @if(isset($data['project_teams']) && count($data['project_teams']) > 0)
    <div class="mb-4">
        <div class="section-header">PROJECT TEAMS</div>
        <table style="width: 100%;">
            @foreach(array_chunk($data['project_teams'], 2) as $teamRow)
            <tr>
                @foreach($teamRow as $team)
                <td style="width: 49%; padding-right: 1%;">
                    <div class="summary-card">
                        <div class="text-blue-600 font-bold uppercase mb-2">{{ $team['category_name'] }} - {{ $team['team_type_name'] }}</div>
                        @if(count($team['members']) > 0)
                            <table style="width: 100%; border-top: 1px solid #e2e8f0; padding-top: 5px;">
                                @foreach($team['members'] as $member)
                                <tr>
                                    <td style="padding: 2px 0;">
                                        <span class="font-bold">{{ $member['name'] }}</span>
                                        @if($member['is_lead'])
                                            <span class="text-emerald-600" style="font-size: 8px;">(Lead)</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right; padding: 2px 0;" class="text-gray-600">{{ $member['phone'] ?? '-' }}</td>
                                </tr>
                                @endforeach
                            </table>
                        @else
                            <div class="text-gray-500 italic" style="font-size: 8px;">No members assigned to this team.</div>
                        @endif
                    </div>
                </td>
                @endforeach
                @if(count($teamRow) < 2)
                <td style="width: 49%;"></td>
                @endif
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    <!-- Loading Sheet -->
    <div class="category-title">LOADING SHEET</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 35%;">Item Description</th>
                <th style="width: 8%; text-align: center;">Qty</th>
                <th style="width: 12%;">Category</th>
                <th style="width: 10%; text-align: center;">Returns?</th>
                <th style="width: 10%; text-align: center;">Type</th>
                <th style="width: 25%;">Handling / Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['transport_items'] as $item)
            <tr>
                <td>{{ $item['name'] }}</td>
                <td class="text-center font-bold">{{ $item['quantity'] }} {{ $item['unit'] }}</td>
                <td class="uppercase">{{ $item['main_category'] ?? $item['category'] }}</td>
                <td class="text-center font-bold {{ ($item['is_returnable'] ?? false) ? 'text-blue-600' : 'text-gray-600' }}">
                    {{ ($item['is_returnable'] ?? false) ? 'YES' : 'NO' }}
                </td>
                <td class="text-center uppercase" style="font-size: 7px;">
                    {{ $item['sub_type'] ?? '-' }}
                </td>
                <td>
                    @if(($item['main_category'] ?? $item['category']) !== 'PRODUCTION')
                        {{ $item['special_handling'] ?? $item['description'] ?? '-' }}
                    @else
                        -
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-center text-gray-600">No items listed in loading sheet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Checklist & Safety -->
    <div style="page-break-inside: avoid;">
        <table style="width: 100%;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <div class="section-header">SAFETY & READY-FOR-TRANSIT</div>
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
                         <div class="checklist-item"><span class="checkbox {{ ($data['checklist']['equipment']['communication'] ?? false) ? 'checked' : '' }}"></span> Comms (Radios)</div>
                    </div>
                </td>
                <td style="width: 48%; padding-left: 2%;">
                     <div class="section-header">LOADING VERIFICATION</div>
                     @if(isset($data['checklist']['items']) && count($data['checklist']['items']) > 0)
                     <table class="data-table">
                         <thead>
                             <tr>
                                 <th>Item Checks</th>
                                 <th>Status</th>
                             </tr>
                         </thead>
                         <tbody>
                             @foreach(array_slice($data['checklist']['items'], 0, 8) as $checkItem)
                             <tr>
                                 <td>{{ $checkItem['item_name'] }}</td>
                                 <td class="uppercase font-bold {{ $checkItem['status'] == 'present' ? 'text-emerald-600' : 'text-red-600' }}">
                                     {{ $checkItem['status'] }}
                                 </td>
                             </tr>
                             @endforeach
                              @if(count($data['checklist']['items']) > 8)
                                 <tr><td colspan="2" class="text-center text-gray-600" style="font-size: 8px;">... and {{ count($data['checklist']['items']) - 8 }} more items</td></tr>
                              @endif
                         </tbody>
                     </table>
                     @else
                        <div class="info-box text-center">No specific checklist items verified.</div>
                     @endif
                </td>
            </tr>
        </table>
    </div>

    <!-- Approvals -->
    <div style="page-break-inside: avoid; margin-top: 20px;">
        <div class="section-header">DISPATCH APPROVALS & SIGN-OFF</div>
        <table style="width: 100%;">
            <tr>
                <td style="width: 33%; padding-right: 2%;">
                     <div class="font-bold text-center mb-1 text-gray-600">{{ ($data['logistics_planning']['transport_arrangement'] ?? 'company') === 'client' ? 'CLIENT DRIVER' : 'DRIVER' }}</div>
                     <div class="signature-box"></div>
                     <div class="text-center mt-1 font-bold">{{ ($data['logistics_planning']['transport_arrangement'] ?? 'company') === 'client' ? 'Client representative' : ($data['logistics_planning']['driver_name'] ?? 'Driver Name') }}</div>
                     <div class="text-center text-gray-600" style="font-size: 8px;">Date: _______</div>
                </td>
                <td style="width: 33%; padding-right: 2%;">
                     <div class="font-bold text-center mb-1 text-gray-600">LOGISTICS MANAGER</div>
                     <div class="signature-box"></div>
                     <div class="text-center mt-1 font-bold">Approved By</div>
                     <div class="text-center text-gray-600" style="font-size: 8px;">Date: _______</div>
                </td>
                 <td style="width: 33%;">
                     <div class="font-bold text-center mb-1 text-gray-600">SECURITY CHECK</div>
                     <div class="signature-box"></div>
                     <div class="text-center mt-1 font-bold">Security / Gate</div>
                     <div class="text-center text-gray-600" style="font-size: 8px;">Date: _______</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd</p>
        <p>Tel: +254 780 397 798 | Email: admin@woodnorkgreen.co.ke</p>
        <p>Karen Village, Ngong Road, Nairobi, Kenya | www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
