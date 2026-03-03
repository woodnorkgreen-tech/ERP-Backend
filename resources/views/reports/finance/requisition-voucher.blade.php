<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>REQUISITION-{{ $requisition->requisition_number }}</title>
    <style>
        @page {
            margin: 0;
            size: A4 portrait;
        }
        body {
            font-family: 'Courier', 'Courier New', monospace;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            background-color: #f1f5f9;
        }
        .container {
            width: 100%;
            max-width: 550px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .paper-effect {
            background-color: #ffffff;
            padding: 0;
        }
        /* Perforated edge mimic */
        .perforated {
            height: 12px;
            background-color: #ffffff;
            border-bottom: 2px dashed #e2e8f0;
            margin: 0 30px;
        }
        .header {
            text-align: center;
            padding: 30px 40px;
            border-bottom: 2px dashed #cbd5e1;
        }
        .logo-box {
            margin-bottom: 15px;
        }
        .logo-img {
            height: 40px;
            vertical-align: middle;
        }
        .company-info {
            display: inline-block;
            text-align: left;
            vertical-align: middle;
            margin-left: 15px;
            border-left: 1px solid #e2e8f0;
            padding-left: 15px;
        }
        .company-name {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #334155;
            margin-bottom: 3px;
        }
        .kra-pin {
            font-size: 9px;
            font-weight: 900;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .doc-title {
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin: 15px 0 5px 0;
        }
        .ref-no {
            font-size: 10px;
            color: #64748b;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 15px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 15px;
        }
        /* Status Colors */
        .status-pending { background-color: #fef3c7; color: #b45309; }
        .status-approved { background-color: #dbeafe; color: #1d4ed8; }
        .status-rejected { background-color: #fee2e2; color: #b91c1c; }
        .status-disbursed { background-color: #f3e8ff; color: #7e22ce; }
        .status-received { background-color: #d1fae5; color: #047857; }

        .body {
            padding: 30px 40px;
        }
        .info-row {
            width: 100%;
            border-bottom: 1px dashed #e2e8f0;
            padding: 8px 0;
        }
        .info-label {
            width: 150px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            font-size: 10px;
        }
        .info-value {
            text-align: right;
            font-weight: 900;
            color: #0f172a;
            text-transform: uppercase;
        }
        .purpose-box {
            padding: 15px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .purpose-label {
            font-size: 10px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .purpose-text {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
        }
        .project-link {
            background-color: #f8fafc;
            padding: 12px;
            border: 1px dashed #e2e8f0;
            margin-top: 15px;
        }
        .project-label {
            font-size: 9px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .project-value {
            font-size: 11px;
            font-weight: 900;
            color: #0f172a;
        }

        .items-header {
            width: 100%;
            font-size: 10px;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 2px;
            border-bottom: 1px solid #334155;
            margin-top: 25px;
            padding-bottom: 5px;
        }
        .item-row {
            width: 100%;
            padding: 15px 0;
            border-bottom: 1px dotted #e2e8f0;
        }
        .item-detail {
            vertical-align: top;
        }
        .item-index {
            color: #94a3b8;
            font-weight: 900;
            font-size: 10px;
            padding-right: 8px;
        }
        .item-payee {
            font-weight: 900;
            color: #0f172a;
            text-transform: uppercase;
        }
        .item-desc {
            font-size: 10px;
            color: #64748b;
            font-style: italic;
            font-weight: bold;
            margin-top: 3px;
        }
        .item-amount-box {
            text-align: right;
            vertical-align: top;
            width: 100px;
        }
        .item-amount {
            font-weight: 900;
            color: #0f172a;
        }
        .item-confirmed {
            font-size: 9px;
            color: #10b981;
            font-weight: 900;
            text-transform: uppercase;
            margin-top: 3px;
        }
        .item-sig-box {
            width: 80px;
            text-align: right;
            vertical-align: top;
        }
        .item-sig-img {
            max-height: 30px;
            max-width: 80px;
            filter: brightness(0);
        }

        .total-container {
            margin-top: 20px;
            background-color: #0f172a;
            color: #ffffff;
            padding: 15px;
            border: 2px double #0f172a;
        }
        .total-label {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .total-value {
            font-size: 20px;
            font-weight: 900;
            float: right;
            margin-top: -5px;
        }

        .rejection-box {
            background-color: #fef2f2;
            border: 2px solid #fca5a5;
            padding: 12px;
            margin-top: 15px;
        }
        .rejection-label {
            font-size: 9px;
            font-weight: 900;
            color: #b91c1c;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .timeline {
            padding: 20px 40px;
            background-color: #f8fafc;
            border-top: 2px dashed #e2e8f0;
        }
        .timeline-title {
            font-size: 10px;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 15px;
        }
        .log-entry {
            width: 100%;
            margin-bottom: 12px;
        }
        .log-dot {
            width: 6px;
            height: 6px;
            border-radius: 3px;
            display: inline-block;
            margin-right: 10px;
        }
        .dot-requested { background-color: #3b82f6; }
        .dot-action { background-color: #10b981; }
        .dot-disbursed { background-color: #a855f7; }
        .log-label {
            font-size: 9px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
        }
        .log-date {
            float: right;
            font-weight: 900;
            color: #0f172a;
        }
        .log-user {
            font-size: 9px;
            color: #94a3b8;
            font-weight: bold;
            margin-left: 20px;
            font-style: italic;
        }

        .signature-footer {
            padding: 30px 40px;
            border-top: 1px dashed #e2e8f0;
            text-align: center;
        }
        .signature-img {
            max-height: 60px;
            filter: brightness(0);
            border-bottom: 2px solid #0f172a;
            margin-bottom: 10px;
        }
        .sig-user-name {
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            color: #0f172a;
        }
        .sig-verify-text {
            font-size: 9px;
            color: #64748b;
            font-weight: 900;
            text-transform: uppercase;
            margin-top: 3px;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="paper-effect">
            <div class="perforated"></div>
            
            <div class="header">
                <div class="logo-box">
                    <img src="{{ public_path('logo-outline.png') }}" class="logo-img" alt="WNG Logo"/>
                    <div class="company-info">
                        <div class="company-name">Woodnork Green</div>
                        <div class="kra-pin">KRA PIN: P051451468C</div>
                        <div style="font-size:7px; font-weight:bold; color:#94a3b8; text-transform:uppercase; margin-top:1px;">Verified Official Document</div>
                    </div>
                </div>
                <div class="doc-title">Petty Cash Voucher</div>
                <div class="ref-no">Ref No: {{ $requisition->requisition_number }}</div>
                
                <div class="status-badge status-{{ strtolower($requisition->status) }}">
                    {{ $requisition->status }}
                </div>
            </div>

            <div class="body">
                <div class="info-row clearfix">
                    <span class="info-label">Voucher Date:</span>
                    <span class="info-value">{{ \Carbon\Carbon::parse($requisition->created_at)->format('d M Y') }}</span>
                </div>
                
                <div class="info-row clearfix">
                    <span class="info-label">Authorized Payee:</span>
                    <span class="info-value">
                        @if($requisition->payee)
                            {{ $requisition->payee->first_name }} {{ $requisition->payee->last_name }}
                            @if($requisition->payee->phone || $requisition->payee_phone)
                                <span style="font-size: 8px; color: #3b82f6; display: block;">Tel: {{ $requisition->payee->phone ?? $requisition->payee_phone }}</span>
                            @endif
                        @elseif($requisition->payee_name)
                            {{ $requisition->payee_name }}
                            @if($requisition->payee_phone)
                                <span style="font-size: 8px; color: #3b82f6; display: block;">Tel: {{ $requisition->payee_phone }}</span>
                            @endif
                        @else
                            {{ $requisition->requester->name ?? 'Public Submission' }}
                            @if($requisition->requester && $requisition->requester->employee && $requisition->requester->employee->phone)
                                <span style="font-size: 8px; color: #3b82f6; display: block;">Tel: {{ $requisition->requester->employee->phone }}</span>
                            @endif
                        @endif
                    </span>
                </div>

                <div class="info-row clearfix">
                    <span class="info-label">Cost Center:</span>
                    <span class="info-value">{{ $requisition->department->name ?? 'N/A' }}</span>
                </div>

                <div class="purpose-box">
                    <div class="purpose-label">Payment Purpose:</div>
                    <div class="purpose-text">{{ $requisition->purpose }}</div>
                </div>

                @if($requisition->project || $requisition->enquiry || $requisition->project_name || $requisition->venue)
                    <div class="project-link">
                        <div class="project-label">Project Assignment & Location:</div>
                        @if($requisition->project)
                            <div class="project-value">
                                {{ $requisition->project->enquiry->job_number ?? $requisition->project->project_id ?? 'Project' }} 
                                - {{ $requisition->project->enquiry->title ?? $requisition->project->title }}
                            </div>
                        @elseif($requisition->enquiry)
                            <div class="project-value">
                                {{ $requisition->enquiry->job_number ?? $requisition->enquiry->enquiry_number }}
                                - {{ $requisition->enquiry->title }}
                            </div>
                        @elseif($requisition->project_name)
                            <div class="project-value">{{ $requisition->project_name }}</div>
                        @endif

                        @if($requisition->venue)
                            <div class="project-value" style="margin-top:5px; font-size:10px; color:#475569;">
                                Venue: {{ $requisition->venue }}
                            </div>
                        @endif
                    </div>
                @endif

                <div class="items-header clearfix">
                    <span style="float:left">Distribution Detail</span>
                    <span style="float:right">Amount</span>
                </div>

                @foreach($requisition->items as $idx => $item)
                    <table class="item-row">
                        <tr>
                            <td class="item-index">{{ $idx + 1 }}.</td>
                            <td class="item-detail">
                                <div class="item-payee">
                                    @if($item->payee_name)
                                    {{ $item->payee_name }}
                                @elseif($item->payee)
                                    {{ $item->payee->first_name }} {{ $item->payee->last_name }}
                                @else
                                    @if($requisition->payee)
                                        {{ $requisition->payee->first_name }} {{ $requisition->payee->last_name }}
                                    @elseif($requisition->payee_name)
                                        {{ $requisition->payee_name }}
                                    @else
                                        {{ $requisition->requester->name ?? 'Public Guest' }}
                                    @endif
                                @endif
                                </div>
                                <div class="item-desc">
                                    @if($item->remarks)
                                        <span style="color: #3b82f6; font-weight: 900;">[TASK: {{ $item->remarks }}]</span> &mdash;
                                    @endif
                                    {{ $item->description }}
                                </div>
                                @if($item->payee_phone || ($item->payee && $item->payee->phone) || ($requisition->requester && $requisition->requester->employee && $requisition->requester->employee->phone))
                                    <div style="font-size: 8px; color: #3b82f6; font-weight: bold; margin-top: 2px;">
                                        Tel: {{ $item->payee_phone ?? ($item->payee ? $item->payee->phone : ($requisition->requester && $requisition->requester->employee ? $requisition->requester->employee->phone : '')) }}
                                    </div>
                                @endif
                            </td>
                            <td class="item-amount-box">
                                <div class="item-amount">KES {{ number_format($item->amount, 2) }}</div>
                                @if($item->received_at)
                                    <div class="item-confirmed">✓ Confirmed</div>
                                @endif
                            </td>
                            @if($item->digital_signature)
                                <td class="item-sig-box">
                                    <img src="{{ $item->digital_signature }}" class="item-sig-img" />
                                </td>
                            @endif
                        </tr>
                    </table>
                @endforeach

                <div class="total-container clearfix">
                    <span class="total-label">Total Disbursed:</span>
                    <span class="total-value">KES {{ number_format($requisition->total_amount, 2) }}</span>
                </div>

                @if($requisition->status === 'rejected')
                    <div class="rejection-box">
                        <div class="rejection-label">Rejected:</div>
                        <div class="purpose-text" style="color:#7f1d1d; font-style:italic;">
                            {{ $requisition->rejection_reason ?? 'No reason provided' }}
                        </div>
                    </div>
                @endif
            </div>

            <div class="timeline">
                <div class="timeline-title">Transaction Log</div>
                
                <!-- Requested -->
                <div class="log-entry clearfix">
                    <div class="log-dot dot-requested"></div>
                    <span class="log-label">Requested</span>
                    <span class="log-date">{{ \Carbon\Carbon::parse($requisition->created_at)->format('d/m/y H:i') }}</span>
                </div>

                <!-- Approved/Rejected -->
                @if($requisition->approved_at)
                    <div class="log-entry clearfix">
                        <div class="log-dot {{ $requisition->status === 'rejected' ? 'dot-action' : 'dot-action' }}" style="background-color: {{ $requisition->status === 'rejected' ? '#ef4444' : '#10b981' }}"></div>
                        <span class="log-label">{{ $requisition->status === 'rejected' ? 'Rejected' : 'Approved' }}</span>
                        <span class="log-date">{{ \Carbon\Carbon::parse($requisition->approved_at)->format('d/m/y H:i') }}</span>
                        <div class="log-user">By: {{ $requisition->approver->name ?? 'System' }}</div>
                    </div>
                @endif

                <!-- Disbursed -->
                @if($requisition->disbursement)
                    <div class="log-entry clearfix">
                        <div class="log-dot dot-disbursed"></div>
                        <span class="log-label">Disbursed</span>
                        <span class="log-date">{{ \Carbon\Carbon::parse($requisition->disbursement->created_at)->format('d/m/y H:i') }}</span>
                        <div class="log-user">Ref: {{ $requisition->disbursement->transaction_code }}</div>
                    </div>
                @endif
            </div>

            <!-- QR Code Placeholder (If needed, otherwise skip) -->
            @if($requisition->status === 'disbursed' && !$requisition->received_at)
                <div style="text-align: center; padding: 20px; border-top: 1px dashed #e2e8f0;">
                    <div style="font-size: 8px; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px;">Awaiting Recipient Confirmation</div>
                    <div style="font-size: 7px; color: #94a3b8; font-family: monospace;">
                        {{ config('app.url') }}/pcr/confirm/{{ $requisition->signing_token }}
                    </div>
                </div>
            @endif

            <!-- Main Digital Signature -->
            @if($requisition->digital_signature)
                <div class="signature-footer">
                    <div style="font-size: 9px; color: #94a3b8; margin-bottom: 10px; text-transform: uppercase;">Acknowledged by:</div>
                    <img src="{{ $requisition->digital_signature }}" class="signature-img" />
                    <div class="sig-user-name">{{ $requisition->received_by ?? ($requisition->requester->name ?? 'Public Guest') }}</div>
                    <div class="sig-verify-text">Verified Digital Signature</div>
                    @if($requisition->received_at)
                        <div style="font-size: 8px; color: #94a3b8; margin-top: 5px;">
                            {{ \Carbon\Carbon::parse($requisition->received_at)->format('d M Y H:i:s') }}
                        </div>
                    @endif
                </div>
            @endif

            <div class="perforated" style="border-top: 2px dashed #e2e8f0; border-bottom: none; margin-bottom: 20px;"></div>
        </div>
    </div>
</body>
</html>
