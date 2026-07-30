<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Site Survey Report - #{{ $siteSurvey->id }}</title>
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
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-900 { color: #111827; }
        .text-red-600 { color: #dc2626; }
        
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

        /* Category Titles (Standard) */
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

        /* Tables for Data */
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 10px; }
        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            border-bottom: 2px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 9px;
        }
        .data-table td {
            border-bottom: 1px solid #f1f5f9;
            padding: 6px 8px;
        }

        .footer {
            margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb;
            text-align: center; color: #4b5563; font-size: 9px;
        }

        .signature-box {
            border: 1px solid #e2e8f0;
            padding: 10px;
            text-align: center;
            background: #fcfcfc;
        }
        .signature-img {
            max-height: 60px;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>

    <!-- Header (Matching Budget) -->
    <table style="margin-bottom: 20px;">
        <tr>
            <td style="width: 50%;">
                <img src="{{ public_path('woodnork-green-logo.png') }}" style="width: 125px; height: auto; margin-bottom: 5px; display: block;" alt="Woodnork Green logo"/>
            </td>
            <td style="width: 50%; text-align: right;">
                <h2 class="text-blue-600 mb-2 uppercase tracking-wide text-2xl" style="margin: 0 0 10px 0;">SITE SURVEY REPORT</h2>
                <div style="display: inline-block; border: 1px solid #d1d5db;">
                    <table>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="padding: 4px 10px;">Date</td>
                            <td class="bg-white text-blue-600 font-bold text-center" style="padding: 4px 10px; width: 100px;">{{ \Carbon\Carbon::parse($siteSurvey->site_visit_date)->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <td class="bg-white text-gray-700 font-bold border-r border-gray-300 text-center uppercase" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">Survey #</td>
                            <td class="bg-white text-red-600 font-bold text-center" style="border-top: 1px solid #d1d5db; padding: 4px 10px;">{{ $siteSurvey->id }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Project Information -->
    <div class="mb-4">
        <div class="section-header" style="width: 40%;">SURVEY INFORMATION</div>
        <div class="info-box">
            <table>
                <tr>
                    <td style="width: 60%;">
                        <div class="mb-2"><span class="font-bold">Client:</span> {{ $siteSurvey->client_name ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Location:</span> {{ $siteSurvey->location ?? 'N/A' }}</div>
                        <div class="mb-2"><span class="font-bold">Project Ref:</span> {{ $siteSurvey->enquiry->title ?? 'N/A' }}</div>
                    </td>
                    <td style="width: 40%;">
                         <div class="mb-2"><span class="font-bold">Contact Person:</span> {{ $siteSurvey->client_contact_person ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Contact:</span> {{ $siteSurvey->client_phone ?? $siteSurvey->client_email ?? 'N/A' }}</div>
                         <div class="mb-2"><span class="font-bold">Status:</span> <span class="uppercase font-bold text-blue-600">{{ $siteSurvey->status ?? 'Draft' }}</span></div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Project Description -->
    <div class="category-title">01. PROJECT SCOPE & DESCRIPTION</div>
    <div class="info-box mb-4 bg-white" style="color: #374151;">
        {{ $siteSurvey->project_description ?? 'No description provided.' }}
    </div>

    @if($siteSurvey->objectives)
    <div class="mb-4">
        <div class="font-bold text-gray-700 mb-2 uppercase" style="font-size: 9px;">Objectives</div>
        <div class="info-box bg-white">{{ $siteSurvey->objectives }}</div>
    </div>
    @endif

    <!-- Site Conditions -->
    <div class="category-title">02. SITE CONDITIONS & ACCESS</div>
    <table class="data-table">
        <tr>
            <th style="width: 25%;">Access Logistics</th>
            <td style="width: 75%;">{{ $siteSurvey->access_logistics ?? 'Standard' }}</td>
        </tr>
        <tr>
            <th>Loading Areas</th>
            <td>{{ $siteSurvey->loading_areas ?? 'Standard' }}</td>
        </tr>
        <tr>
            <th>Parking</th>
            <td>{{ $siteSurvey->parking_availability ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Lifts / Elevators</th>
            <td>{{ $siteSurvey->lifts ?? 'N/A' }}</td>
        </tr>
    </table>

    <table class="data-table">
        <tr>
            <th style="width: 25%;">Measurements</th>
            <td style="width: 25%;">{{ $siteSurvey->site_measurements ?? 'N/A' }}</td>
            <th style="width: 25%;">Room Size</th>
            <td style="width: 25%;">{{ $siteSurvey->room_size ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Door Sizes</th>
            <td>{{ $siteSurvey->door_sizes ?? 'N/A' }}</td>
            <th>Ceiling/Height</th>
            <td>{{ $siteSurvey->size_accessibility ?? 'N/A' }}</td>
        </tr>
    </table>

    <!-- Technical Specs -->
    <div class="category-title">03. TECHNICAL REQUIREMENTS</div>
    <table class="data-table">
        <tr>
            <th style="width: 25%;">Electrical</th>
            <td style="width: 75%;">{{ $siteSurvey->electrical_outlets ?? 'None specified' }}</td>
        </tr>
        <tr>
            <th>Existing Branding</th>
            <td>{{ $siteSurvey->existing_branding ?? 'None' }}</td>
        </tr>
        <tr>
            <th>Branding Prefs</th>
            <td>{{ $siteSurvey->branding_preferences ?? 'Standard' }}</td>
        </tr>
        <tr>
            <th>Materials</th>
            <td>{{ $siteSurvey->material_preferences ?? 'Standard' }}</td>
        </tr>
        <tr>
            <th>Constraints</th>
            <td class="text-red-600">{{ $siteSurvey->constraints ?? 'None identified' }}</td>
        </tr>
    </table>

    <!-- Safety & Notes -->
    @if($siteSurvey->safety_conditions || $siteSurvey->safety_requirements)
    <div class="category-title">04. SAFETY & COMPLIANCE</div>
    <div class="info-box bg-white mb-4">
        <div class="mb-2"><span class="font-bold">Conditions:</span> {{ $siteSurvey->safety_conditions ?? 'Standard' }}</div>
        <div><span class="font-bold">Requirements:</span> {{ $siteSurvey->safety_requirements ?? 'Helmet, Vest, Boots' }}</div>
    </div>
    @endif

    <!-- Action Items -->
    @if($siteSurvey->action_items && count($siteSurvey->action_items) > 0)
    <div class="category-title">05. ACTION ITEMS</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 10%;">#</th>
                <th style="width: 90%;">Action Item</th>
            </tr>
        </thead>
        <tbody>
            @foreach($siteSurvey->action_items as $index => $item)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $item }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Signatures -->
    <div style="margin-top: 40px; page-break-inside: avoid;">
        <table style="width: 100%; gap: 20px;">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <div class="signature-box">
                        <div style="height: 60px; display: flex; align-items: center; justify-content: center;">
                            @if($siteSurvey->prepared_signature)
                            <img src="data:image/png;base64,{{ $siteSurvey->prepared_signature }}" class="signature-img" />
                            @endif
                        </div>
                        <div style="border-top: 1px solid #cbd5e1; margin-top: 5px; padding-top: 5px;">
                            <div class="font-bold uppercase text-gray-600" style="font-size: 9px;">Prepared By</div>
                            <div class="font-bold">{{ $siteSurvey->prepared_by ?? 'Surveyor' }}</div>
                        </div>
                    </div>
                </td>
                <td style="width: 48%; padding-left: 2%;">
                    <div class="signature-box">
                        <div style="height: 60px; display: flex; align-items: center; justify-content: center;">
                            @if($siteSurvey->client_signature)
                            <img src="data:image/png;base64,{{ $siteSurvey->client_signature }}" class="signature-img" />
                            @endif
                        </div>
                        <div style="border-top: 1px solid #cbd5e1; margin-top: 5px; padding-top: 5px;">
                            <div class="font-bold uppercase text-gray-600" style="font-size: 9px;">Client Approval</div>
                            <div class="font-bold">{{ $siteSurvey->client_name ?? 'Client Rep' }}</div>
                        </div>
                    </div>
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
