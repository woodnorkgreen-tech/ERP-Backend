<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Survey Report</title>
    <style>
        /* Modern Minimalist PDF Design */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #1a1a1a;
            background: #ffffff;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0.75in;
        }

        /* Header Section */
        .header {
            text-align: center;
            padding-bottom: 30px;
            margin-bottom: 40px;
            border-bottom: 1px solid #e5e7eb;
        }

        .header-title {
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.5px;
            color: #1a1a1a;
            margin-bottom: 12px;
        }

        .header-meta {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .meta-item {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            color: #2563eb;
            font-weight: 500;
        }

        /* Section Styling */
        .section {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }

        .section-header {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #2563eb;
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        /* Field Groups */
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px 30px;
        }

        .field-grid-3 {
            grid-template-columns: repeat(3, 1fr);
        }

        .field {
            margin-bottom: 0;
        }

        .field-label {
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 6px;
            display: block;
        }

        .field-value {
            font-size: 13px;
            color: #1a1a1a;
            line-height: 1.5;
        }

        .field-full {
            grid-column: 1 / -1;
        }

        /* Lists */
        .list-container {
            margin-top: 12px;
        }

        .list-item {
            font-size: 12px;
            color: #1a1a1a;
            padding-left: 18px;
            position: relative;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .list-item:before {
            content: "";
            position: absolute;
            left: 0;
            top: 9px;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #2563eb;
        }

        /* Signature Section */
        .signature-section {
            margin-top: 60px;
            padding-top: 30px;
            border-top: 1px solid #e5e7eb;
            page-break-inside: avoid;
        }

        .signature-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            margin-top: 25px;
        }

        .signature-box {
            text-align: center;
        }

        .signature-container {
            width: 100%;
            height: 90px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fafafa;
        }

        .signature-container img {
            max-width: 90%;
            max-height: 80px;
        }

        .signature-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            font-weight: 500;
        }

        .signature-name {
            font-size: 12px;
            color: #1a1a1a;
            margin-top: 4px;
        }

        .approval-date {
            text-align: center;
            margin-top: 20px;
            font-size: 11px;
            color: #6b7280;
        }

        /* Footer */
        .footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
            line-height: 1.6;
        }

        /* Page Breaks */
        @page {
            margin: 0;
        }

        .page-break {
            page-break-before: always;
        }

        /* Utility Classes */
        .text-center {
            text-align: center;
        }

        .mt-small {
            margin-top: 12px;
        }

        .mt-medium {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1 class="header-title">Site Survey Report</h1>
            <div class="header-meta">
                <span class="meta-item">Survey ID <span class="meta-value">#{{ $siteSurvey->id }}</span></span>
                <span class="meta-item">Date <span class="meta-value">{{ \Carbon\Carbon::parse($siteSurvey->site_visit_date)->format('M j, Y') }}</span></span>
                @if($siteSurvey->enquiry)
                <span class="meta-item">Project <span class="meta-value">{{ $siteSurvey->enquiry->title }}</span></span>
                @endif
            </div>
        </div>

        <!-- Basic Information -->
        <div class="section">
            <div class="section-header">Survey Details</div>
            <div class="field-grid">
                <div class="field">
                    <span class="field-label">Client Name</span>
                    <div class="field-value">{{ $siteSurvey->client_name ?? 'N/A' }}</div>
                </div>
                <div class="field">
                    <span class="field-label">Location</span>
                    <div class="field-value">{{ $siteSurvey->location ?? 'N/A' }}</div>
                </div>
                <div class="field">
                    <span class="field-label">Contact Person</span>
                    <div class="field-value">{{ $siteSurvey->client_contact_person ?? 'N/A' }}</div>
                </div>
                <div class="field">
                    <span class="field-label">Phone</span>
                    <div class="field-value">{{ $siteSurvey->client_phone ?? 'N/A' }}</div>
                </div>
                <div class="field field-full">
                    <span class="field-label">Email</span>
                    <div class="field-value">{{ $siteSurvey->client_email ?? 'N/A' }}</div>
                </div>
            </div>
            
            @if($siteSurvey->attendees && count($siteSurvey->attendees) > 0)
            <div class="mt-medium">
                <span class="field-label">Attendees</span>
                <div class="list-container">
                    @foreach($siteSurvey->attendees as $attendee)
                    <div class="list-item">{{ $attendee }}</div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <!-- Site Assessment -->
        <div class="section">
            <div class="section-header">Site Assessment</div>
            <div class="field field-full">
                <span class="field-label">Project Description</span>
                <div class="field-value">{{ $siteSurvey->project_description ?? 'N/A' }}</div>
            </div>

            @if($siteSurvey->objectives)
            <div class="field field-full mt-medium">
                <span class="field-label">Objectives</span>
                <div class="field-value">{{ $siteSurvey->objectives }}</div>
            </div>
            @endif

            <div class="field-grid mt-medium">
                @if($siteSurvey->current_condition)
                <div class="field">
                    <span class="field-label">Current Condition</span>
                    <div class="field-value">{{ $siteSurvey->current_condition }}</div>
                </div>
                @endif

                @if($siteSurvey->existing_branding)
                <div class="field">
                    <span class="field-label">Existing Branding</span>
                    <div class="field-value">{{ $siteSurvey->existing_branding }}</div>
                </div>
                @endif

                @if($siteSurvey->site_measurements)
                <div class="field">
                    <span class="field-label">Site Measurements</span>
                    <div class="field-value">{{ $siteSurvey->site_measurements }}</div>
                </div>
                @endif

                @if($siteSurvey->room_size)
                <div class="field">
                    <span class="field-label">Room/Area Size</span>
                    <div class="field-value">{{ $siteSurvey->room_size }}</div>
                </div>
                @endif

                @if($siteSurvey->constraints)
                <div class="field field-full">
                    <span class="field-label">Constraints</span>
                    <div class="field-value">{{ $siteSurvey->constraints }}</div>
                </div>
                @endif
            </div>
        </div>

        <!-- Access & Logistics -->
        @if($siteSurvey->access_logistics || $siteSurvey->parking_availability || $siteSurvey->size_accessibility || $siteSurvey->lifts || $siteSurvey->door_sizes || $siteSurvey->loading_areas)
        <div class="section">
            <div class="section-header">Access & Logistics</div>
            <div class="field-grid">
                @if($siteSurvey->access_logistics)
                <div class="field field-full">
                    <span class="field-label">Access Logistics</span>
                    <div class="field-value">{{ $siteSurvey->access_logistics }}</div>
                </div>
                @endif

                @if($siteSurvey->parking_availability)
                <div class="field">
                    <span class="field-label">Parking Availability</span>
                    <div class="field-value">{{ $siteSurvey->parking_availability }}</div>
                </div>
                @endif

                @if($siteSurvey->size_accessibility)
                <div class="field">
                    <span class="field-label">Size & Accessibility</span>
                    <div class="field-value">{{ $siteSurvey->size_accessibility }}</div>
                </div>
                @endif

                @if($siteSurvey->lifts)
                <div class="field">
                    <span class="field-label">Lifts/Elevators</span>
                    <div class="field-value">{{ $siteSurvey->lifts }}</div>
                </div>
                @endif

                @if($siteSurvey->door_sizes)
                <div class="field">
                    <span class="field-label">Door Sizes</span>
                    <div class="field-value">{{ $siteSurvey->door_sizes }}</div>
                </div>
                @endif

                @if($siteSurvey->loading_areas)
                <div class="field field-full">
                    <span class="field-label">Loading Areas</span>
                    <div class="field-value">{{ $siteSurvey->loading_areas }}</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Requirements & Preferences -->
        @if($siteSurvey->branding_preferences || $siteSurvey->material_preferences || $siteSurvey->color_scheme || $siteSurvey->brand_guidelines || $siteSurvey->electrical_outlets || $siteSurvey->food_refreshment || $siteSurvey->special_instructions)
        <div class="section">
            <div class="section-header">Requirements & Preferences</div>
            <div class="field-grid">
                @if($siteSurvey->branding_preferences)
                <div class="field">
                    <span class="field-label">Branding Preferences</span>
                    <div class="field-value">{{ $siteSurvey->branding_preferences }}</div>
                </div>
                @endif

                @if($siteSurvey->material_preferences)
                <div class="field">
                    <span class="field-label">Material Preferences</span>
                    <div class="field-value">{{ $siteSurvey->material_preferences }}</div>
                </div>
                @endif

                @if($siteSurvey->color_scheme)
                <div class="field">
                    <span class="field-label">Color Scheme</span>
                    <div class="field-value">{{ $siteSurvey->color_scheme }}</div>
                </div>
                @endif

                @if($siteSurvey->electrical_outlets)
                <div class="field">
                    <span class="field-label">Electrical Requirements</span>
                    <div class="field-value">{{ $siteSurvey->electrical_outlets }}</div>
                </div>
                @endif

                @if($siteSurvey->food_refreshment)
                <div class="field">
                    <span class="field-label">Food & Refreshment</span>
                    <div class="field-value">{{ $siteSurvey->food_refreshment }}</div>
                </div>
                @endif

                @if($siteSurvey->special_instructions)
                <div class="field">
                    <span class="field-label">Special Instructions</span>
                    <div class="field-value">{{ $siteSurvey->special_instructions }}</div>
                </div>
                @endif

                @if($siteSurvey->brand_guidelines)
                <div class="field field-full">
                    <span class="field-label">Brand Guidelines</span>
                    <div class="field-value">{{ $siteSurvey->brand_guidelines }}</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Safety & Timeline -->
        @if($siteSurvey->safety_conditions || $siteSurvey->potential_hazards || $siteSurvey->safety_requirements || $siteSurvey->project_start_date || $siteSurvey->project_deadline || $siteSurvey->milestones)
        <div class="section">
            <div class="section-header">Safety & Timeline</div>
            <div class="field-grid">
                @if($siteSurvey->safety_conditions)
                <div class="field">
                    <span class="field-label">Safety Conditions</span>
                    <div class="field-value">{{ $siteSurvey->safety_conditions }}</div>
                </div>
                @endif

                @if($siteSurvey->potential_hazards)
                <div class="field">
                    <span class="field-label">Potential Hazards</span>
                    <div class="field-value">{{ $siteSurvey->potential_hazards }}</div>
                </div>
                @endif

                @if($siteSurvey->safety_requirements)
                <div class="field field-full">
                    <span class="field-label">Safety Requirements</span>
                    <div class="field-value">{{ $siteSurvey->safety_requirements }}</div>
                </div>
                @endif

                @if($siteSurvey->project_start_date)
                <div class="field">
                    <span class="field-label">Project Start Date</span>
                    <div class="field-value">{{ \Carbon\Carbon::parse($siteSurvey->project_start_date)->format('F j, Y') }}</div>
                </div>
                @endif

                @if($siteSurvey->project_deadline)
                <div class="field">
                    <span class="field-label">Project Deadline</span>
                    <div class="field-value">{{ \Carbon\Carbon::parse($siteSurvey->project_deadline)->format('F j, Y') }}</div>
                </div>
                @endif

                @if($siteSurvey->milestones)
                <div class="field field-full">
                    <span class="field-label">Key Milestones</span>
                    <div class="field-value">{{ $siteSurvey->milestones }}</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Additional Information -->
        @if($siteSurvey->additional_notes || $siteSurvey->special_requests || ($siteSurvey->action_items && count($siteSurvey->action_items) > 0))
        <div class="section">
            <div class="section-header">Additional Information</div>
            
            @if($siteSurvey->additional_notes)
            <div class="field field-full">
                <span class="field-label">Additional Notes</span>
                <div class="field-value">{{ $siteSurvey->additional_notes }}</div>
            </div>
            @endif

            @if($siteSurvey->special_requests)
            <div class="field field-full mt-medium">
                <span class="field-label">Special Requests</span>
                <div class="field-value">{{ $siteSurvey->special_requests }}</div>
            </div>
            @endif

            @if($siteSurvey->action_items && count($siteSurvey->action_items) > 0)
            <div class="mt-medium">
                <span class="field-label">Action Items</span>
                <div class="list-container">
                    @foreach($siteSurvey->action_items as $item)
                    <div class="list-item">{{ $item }}</div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="field-grid mt-medium">
                @if($siteSurvey->prepared_by)
                <div class="field">
                    <span class="field-label">Prepared By</span>
                    <div class="field-value">{{ $siteSurvey->prepared_by }}</div>
                </div>
                @endif

                @if($siteSurvey->prepared_date)
                <div class="field">
                    <span class="field-label">Prepared Date</span>
                    <div class="field-value">{{ \Carbon\Carbon::parse($siteSurvey->prepared_date)->format('F j, Y') }}</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Signatures -->
        <div class="signature-section">
            <div class="section-header">Approvals & Signatures</div>
            
            <div class="signature-grid">
                <div class="signature-box">
                    <div class="signature-container">
                        @if($siteSurvey->prepared_signature)
                        <img src="data:image/png;base64,{{ $siteSurvey->prepared_signature }}" alt="Prepared By Signature" />
                        @endif
                    </div>
                    <div class="signature-label">Prepared By</div>
                    <div class="signature-name">{{ $siteSurvey->prepared_by ?? 'Survey Conductor' }}</div>
                </div>

                <div class="signature-box">
                    <div class="signature-container">
                        @if($siteSurvey->client_signature)
                        <img src="data:image/png;base64,{{ $siteSurvey->client_signature }}" alt="Client Signature" />
                        @endif
                    </div>
                    <div class="signature-label">Client Approval</div>
                    <div class="signature-name">{{ $siteSurvey->client_name ?? 'Client Representative' }}</div>
                </div>
            </div>

            @if($siteSurvey->client_approval_date)
            <div class="approval-date">
                Client approved on {{ \Carbon\Carbon::parse($siteSurvey->client_approval_date)->format('F j, Y') }}
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <div>Site Survey Report • Generated {{ now()->format('F j, Y') }}</div>
            <div>Survey ID: {{ $siteSurvey->id }} @if($siteSurvey->enquiry)• Project: {{ $siteSurvey->enquiry->title }}@endif</div>
        </div>
    </div>
</body>
</html>
