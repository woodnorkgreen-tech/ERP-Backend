<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Archival Report - {{ $report->project_code ?? 'Draft' }}</title>
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
        
        /* Layout Utilities */
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

        /* Tables */
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
        
        /* Grid Layout for PDF */
        .grid-row { width: 100%; margin-bottom: 15px; }
        .grid-col-2 { width: 48%; display: inline-block; vertical-align: top; }
        .gap { width: 2%; display: inline-block; }

        /* Checklist & Signature */
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
                <h2 class="text-green-500 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">ARCHIVAL REPORT</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ now()->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">ID</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">#{{ $report->id }}</td>
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
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ $report->client_name ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Project Name:</span> {{ $report->project_scope ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Project Code:</span> <span class="text-red-600 font-bold">{{ $report->project_code ?? 'N/A' }}</span></div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Location:</span> {{ $report->site_location ?? 'TBC' }}</div>
                         <div class="mb-2"><span class="font-bold">Period:</span> 
                            {{ $report->start_date ? \Carbon\Carbon::parse($report->start_date)->format('d/m/Y') : 'TBC' }} - 
                            {{ $report->end_date ? \Carbon\Carbon::parse($report->end_date)->format('d/m/Y') : 'TBC' }}
                        </div>
                        <div class="mb-2"><span class="font-bold">Officer:</span> {{ $report->project_officer ?? 'N/A' }}</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Scope, Procurement & Fabrication -->
    <div class="mb-4">
        <table>
            <tr>
                <td style="width: 32%; padding-right: 1.3%;">
                    <div class="section-header">SCOPE SUMMARY</div>
                    <div class="info-box" style="height: 80px;">
                        {{ \Illuminate\Support\Str::limit($report->project_scope, 150) ?? 'No scope defined.' }}
                    </div>
                </td>
                <td style="width: 32%; padding-right: 1.3%;">
                    <div class="section-header">PROCUREMENT</div>
                    <div class="info-box" style="height: 80px;">
                         <div class="mb-2"><span class="font-bold">Externally Sourced:</span> {{ $report->items_sourced_externally ?? 'None' }}</div>
                         <div class="mb-2"><span class="font-bold">Challenges:</span> {{ $report->procurement_challenges ?? 'None' }}</div>
                         <div class="mb-2"><span class="font-bold">MRF Attached:</span> {{ $report->materials_mrf_attached ? 'Yes' : 'No' }}</div>
                    </div>
                </td>
                <td style="width: 32%;">
                    <div class="section-header">FABRICATION</div>
                    <div class="info-box" style="height: 80px;">
                         <div class="mb-2"><span class="font-bold">Prod. Start:</span> {{ $report->production_start_date ? \Carbon\Carbon::parse($report->production_start_date)->format('d/m/Y') : 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Packaging:</span> {{ $report->packaging_labeling_status ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Materials:</span> {{ \Illuminate\Support\Str::limit($report->materials_used_in_production, 50) ?? 'N/A' }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Setup & Team -->
    <div class="mb-4">
        <div class="section-header">ON-SITE SETUP & FINDINGS</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="20%">Team Captain</th>
                    <th width="25%">Setup Team</th>
                    <th width="25%">Branding Team</th>
                    <th width="15%">Organization</th>
                    <th width="15%">Deliverables</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $report->team_captain ?? 'N/A' }}</td>
                    <td>{{ $report->setup_team_assigned ?? 'N/A' }}</td>
                    <td>{{ $report->branding_team_assigned ?? 'N/A' }}</td>
                    <td class="uppercase">{{ $report->site_organization ?? 'N/A' }}</td>
                    <td>
                        {{ $report->all_deliverables_available ? 'All Available' : 'Missing Items' }}<br>
                        {{ $report->deliverables_checked ? 'Checked' : 'Not Checked' }}
                    </td>
                </tr>
            </tbody>
        </table>
        @if($report->general_findings || $report->delays_occurred)
        <div class="info-box" style="margin-top: 5px;">
            <span class="font-bold">Findings/Notes:</span> {{ $report->general_findings ?? 'N/A' }} 
            @if($report->delays_occurred) <br><span class="font-bold text-red-600">Delays Reported:</span> {{ $report->delay_reasons }} @endif
        </div>
        @endif
    </div>

    <!-- Setup Items (If any) -->
    @if($report->setupItems && count($report->setupItems) > 0)
    <div class="mb-4">
         <div class="section-header">SETUP ITEMS & TECHNICIANS</div>
         <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Assigned Tech</th>
                    <th>Site Section</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report->setupItems as $item)
                <tr>
                    <td>{{ $item['deliverable_item'] ?? '-' }}</td>
                    <td>{{ $item['assigned_technician'] ?? '-' }}</td>
                    <td>{{ $item['site_section'] ?? '-' }}</td>
                    <td class="uppercase">{{ $item['status'] ?? '-' }}</td>
                </tr>
                @endforeach
            </tbody>
         </table>
    </div>
    @endif

    <!-- Performance & Quality Metrics (Combined Table) -->
    <div class="mb-4">
        <div class="section-header">PERFORMANCE & QUALITY METRICS</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Rating</th>
                    <th>Comments / Feedback</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="font-bold">Print Clarity & Accuracy</td>
                    <td class="uppercase">{{ $report->print_clarity_rating ?? '-' }} / {{ $report->printworks_accuracy_rating ?? '-' }}</td>
                    <td>{{ $report->installation_precision_comments ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="font-bold">Setup Speed & Coordination</td>
                    <td class="uppercase">{{ $report->setup_speed_flow ?? '-' }} / {{ $report->team_coordination ?? '-' }}</td>
                    <td>{{ $report->efficiency_remarks ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="font-bold">Delivery (Schedule & Condition)</td>
                    <td class="uppercase">
                        {{ $report->delivered_on_schedule ? 'On Time' : 'Delayed' }} / {{ $report->delivery_condition ?? '-' }}
                    </td>
                    <td>{{ $report->delivery_notes ?? ($report->delivery_issues ? 'Issues Reported' : 'No Issues') }}</td>
                </tr>
                <tr>
                    <td class="font-bold">Team Professionalism</td>
                    <td class="uppercase">{{ $report->team_professionalism ?? '-' }}</td>
                    <td>{{ $report->professionalism_feedback ?? '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Client Handover -->
    <div class="mb-4">
        <div class="section-header">CLIENT HANDOVER & SATISFACTION</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Handover Date</th>
                    <th>Client Rating</th>
                    <th>Satisfaction</th>
                    <th>Confidence</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $report->handover_date ? \Carbon\Carbon::parse($report->handover_date)->format('d/m/Y') : 'N/A' }}</td>
                    <td class="font-bold">{{ $report->client_rating ?? 'N/A' }}</td>
                    <td class="uppercase font-bold text-green-500">{{ $report->client_satisfaction ?? 'N/A' }}</td>
                    <td>{{ $report->client_confidence ? 'Yes' : 'No' }}</td>
                </tr>
                <tr>
                    <td colspan="4" class="bg-gray-100">
                        <span class="font-bold">Client Remarks:</span> {{ $report->client_remarks ?? 'None' }}
                    </td>
                </tr>
                 <tr>
                    <td colspan="4">
                        <span class="font-bold">Actions/Recommendations:</span> {{ $report->recommendations_action_points ?? 'None' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Set-Down & Checklist -->
    <div class="mb-4">
        <table>
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <div class="section-header">SET-DOWN & DEBRIEF</div>
                     <table class="data-table">
                        <tr><td class="font-bold">Date</td><td>{{ $report->setdown_date ? \Carbon\Carbon::parse($report->setdown_date)->format('d/m/Y') : 'N/A' }}</td></tr>
                        <tr><td class="font-bold">Clearance</td><td>{{ $report->site_clearance_status ?? 'N/A' }}</td></tr>
                        <tr><td class="font-bold">Returns Condition</td><td>{{ $report->items_condition_returned ?? 'N/A' }}</td></tr>
                        @if($report->outstanding_items)
                        <tr><td class="font-bold text-red-600">Outstanding</td><td>{{ $report->outstanding_items }}</td></tr>
                        @endif
                    </table>
                </td>
                <td style="width: 48%; padding-left: 2%;">
                    <div class="section-header">ARCHIVAL CHECKLIST</div>
                    <div class="info-box">
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_ppt ? 'checked' : '' }}"></span> Presentation (PPT)</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_cutlist ? 'checked' : '' }}"></span> Cutlist & Tech Specs</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_site_survey_form ? 'checked' : '' }}"></span> Site Survey Form</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_project_budget_file ? 'checked' : '' }}"></span> Budget File</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_material_list ? 'checked' : '' }}"></span> Material List</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_qc_checklist ? 'checked' : '' }}"></span> QC Checklist</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_setup_setdown ? 'checked' : '' }}"></span> Setup/Setdown</div>
                        <div class="checklist-item"><span class="checkbox {{ $report->checklist_client_feedback ? 'checked' : '' }}"></span> Client Feedback</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Records & Attachments -->
    <div class="mb-4">
         <div class="section-header">RECORDS & ATTACHMENTS</div>
         <div class="info-box">
            <div style="margin-bottom: 5px;">
                <span class="font-bold">Reference:</span> {{ $report->archive_reference ?? 'N/A' }} | 
                <span class="font-bold">Location:</span> {{ $report->archive_location ?? 'N/A' }} | 
                <span class="font-bold">Retention:</span> {{ $report->retention_period ?? 'N/A' }}
            </div>
            @if($report->attachments && count($report->attachments) > 0)
            <div style="border-top: 1px solid #d1d5db; padding-top: 5px; margin-top: 5px;">
                <span class="font-bold">Attached Files:</span>
                @foreach($report->attachments as $att)
                    <span class="bg-white border px-1" style="font-size: 9px; margin-right: 5px;">{{ $att['name'] ?? 'File' }}</span>
                @endforeach
            </div>
            @endif
         </div>
    </div>

    <!-- Signatures -->
    <div style="page-break-inside: avoid;">
        <div class="section-header">APPROVALS</div>
        <table>
            <tr>
                <td style="width: 40%; padding-right: 5%;">
                     <div class="font-bold text-center mb-1 text-gray-600">PROJECT OFFICER</div>
                     <div class="signature-box">
                         @if($report->project_officer_signature)
                           <div style="font-family: 'Brush Script MT', cursive; font-size: 18px; padding-top: 20px;">{{ $report->project_officer_signature }}</div>
                         @endif
                     </div>
                     <div class="text-center mt-1 font-bold">{{ $report->project_officer ?? 'N/A' }}</div>
                     <div class="text-center text-gray-600 font-small">{{ $report->project_officer_sign_date ? \Carbon\Carbon::parse($report->project_officer_sign_date)->format('d/m/Y') : 'Date: _______' }}</div>
                </td>
                <td style="width: 40%; padding-left: 5%;">
                     <div class="font-bold text-center mb-1 text-gray-600">REVIEWED BY</div>
                     <div class="signature-box">
                          @if($report->reviewed_by)
                           <div style="font-family: 'Brush Script MT', cursive; font-size: 18px; padding-top: 20px;">{{ $report->reviewed_by }}</div>
                         @endif
                     </div>
                     <div class="text-center mt-1 font-bold">{{ $report->reviewed_by ?? 'N/A' }}</div>
                     <div class="text-center text-gray-600 font-small">{{ $report->reviewer_sign_date ? \Carbon\Carbon::parse($report->reviewer_sign_date)->format('d/m/Y') : 'Date: _______' }}</div>
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
