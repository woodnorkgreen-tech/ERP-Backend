<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Budget Variance Audit - {{ $enquiry->job_number ?? $enquiry->enquiry_number ?? 'Audit' }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 9px; color: #111827; margin: 0; padding: 0; line-height: 1.3; }
        @page { margin: 0.4in; }
        
        .text-blue-600 { color: #2563eb; }
        .text-red-600 { color: #dc2626; }
        .text-emerald-600 { color: #059669; }
        .text-amber-600 { color: #d97706; }
        .text-gray-500 { color: #6b7280; }
        
        .bg-blue-600 { background-color: #2563eb; color: white; }
        .bg-gray-50 { background-color: #f9fafb; }
        .bg-slate-50 { background-color: #f8fafc; }
        
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .mb-4 { margin-bottom: 20px; }
        
        .header-table { width: 100%; margin-bottom: 20px; }
        .header-table td { vertical-align: top; }
        
        .audit-banner {
            background-color: #1e293b;
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .section-title {
            background-color: #f1f5f9;
            border-left: 4px solid #334155;
            padding: 5px 10px;
            font-weight: bold;
            font-size: 10px;
            margin: 15px 0 10px 0;
            text-transform: uppercase;
        }
        
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: bold;
            text-align: left;
            padding: 8px;
            border-bottom: 2px solid #e2e8f0;
            font-size: 8px;
            text-transform: uppercase;
        }
        .data-table td { border-bottom: 1px solid #f1f5f9; padding: 8px; vertical-align: middle; }
        
        .variance-positive { color: #dc2626; font-weight: bold; }
        .variance-negative { color: #059669; font-weight: bold; }
        .variance-neutral { color: #6b7280; }

        .summary-grid { width: 100%; margin-bottom: 20px; }
        .summary-card {
            border: 1px solid #e2e8f0;
            padding: 10px;
            text-align: center;
        }
        .summary-label { font-size: 8px; text-transform: uppercase; color: #64748b; margin-bottom: 5px; }
        .summary-value { font-size: 14px; font-weight: bold; }

        .footer {
            margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 10px;
            text-align: center; color: #64748b; font-size: 8px;
        }
        
        .badge {
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
            display: inline-block;
            text-transform: uppercase;
        }
        .badge-new { background-color: #ecfdf5; color: #059669; }
        .badge-removed { background-color: #fef2f2; color: #dc2626; }
        .badge-shift { background-color: #fffbeb; color: #d97706; }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <img src="{{ public_path('logo-outline.png') }}" style="height: 50px; margin-bottom: 10px;"/>
                <div style="font-size: 16px; font-weight: bold;">WOODNORK GREEN LTD</div>
                <div class="text-gray-500">Financial Audit & Variance Report</div>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="text-blue-600 font-bold" style="font-size: 18px;">REPORT #AUD-{{ time() }}</div>
                <div class="mb-2">Generated: {{ now()->format('d M Y, H:i') }}</div>
                <div class="font-bold">Job: {{ $enquiry->job_number ?? $enquiry->enquiry_number ?? 'N/A' }}</div>
            </td>
        </tr>
    </table>

    <div class="audit-banner">
        <table style="width: 100%;">
            <tr>
                <td>
                    <div style="font-size: 8px; opacity: 0.8; text-transform: uppercase;">Comparing Current Budget Against</div>
                    <div style="font-size: 12px; font-weight: bold;">{{ $baselineInfo['title'] ?? 'Baseline Snapshot' }}</div>
                </td>
                <td class="text-right">
                    <div style="font-size: 8px; opacity: 0.8; text-transform: uppercase;">Total Variance</div>
                    <div style="font-size: 16px; font-weight: bold;">{{ number_format($auditSummary['totalVariance'] ?? 0, 2) }} KES</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Executive Summary -->
    <div class="section-title">Executive Audit Summary</div>
    <table class="summary-grid">
        <tr>
            <td style="width: 20%; padding-right: 8px;">
                <div class="summary-card">
                    <div class="summary-label">Current Request</div>
                    <div class="summary-value text-blue-600">
                        {{ number_format($currentTotal ?? 0, 0) }}
                    </div>
                </div>
            </td>
            <td style="width: 20%; padding-right: 8px;">
                <div class="summary-card">
                    <div class="summary-label">Baseline Total</div>
                    <div class="summary-value text-gray-500">
                        {{ number_format($baselineTotal ?? 0, 0) }}
                    </div>
                </div>
            </td>
            <td style="width: 20%; padding-right: 8px;">
                <div class="summary-card">
                    <div class="summary-label">Net Financial Delta</div>
                    <div class="summary-value {{ ($auditSummary['totalVariance'] ?? 0) > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                        {{ ($auditSummary['totalVariance'] ?? 0) > 0 ? '+' : '' }}{{ number_format($auditSummary['totalVariance'] ?? 0, 0) }}
                    </div>
                </div>
            </td>
            <td style="width: 20%; padding-right: 8px;">
                <div class="summary-card">
                    <div class="summary-label">Vol. Variance</div>
                    <div class="summary-value text-amber-600">
                        {{ number_format($auditSummary['volumeVariance'] ?? 0, 0) }}
                    </div>
                </div>
            </td>
            <td style="width: 20%;">
                <div class="summary-card">
                    <div class="summary-label">Changed Items</div>
                    <div class="summary-value">{{ count($variances) }}</div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Detailed Variance Log -->
    <div class="section-title">Variance Log: Component Level Analysis</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 35%;">Item / Scope Detail</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 15%; text-align: center;">Qty (Base &rarr; Curr)</th>
                <th style="width: 20%; text-align: right;">Financial Impact</th>
                <th style="width: 20%; text-align: right;">Variance Root Cause</th>
            </tr>
        </thead>
        <tbody>
            @forelse($variances as $var)
            <tr>
                <td>
                    <div class="font-bold">{{ $var['description'] }}</div>
                    <div class="text-gray-500" style="font-size: 7px;">Ref: {{ $var['comparisonKey'] ?? 'N/A' }}</div>
                </td>
                <td class="text-center">
                    @if($var['isNew'] ?? false)
                        <span class="badge badge-new">New</span>
                    @elseif($var['isRemoved'] ?? false)
                        <span class="badge badge-removed">Removed</span>
                    @else
                        <span class="badge badge-shift">Updated</span>
                    @endif
                </td>
                <td class="text-center">
                    {{ $var['baselineQty'] }} &rarr; {{ $var['currentQty'] }}
                </td>
                <td class="text-right font-bold {{ $var['variance'] > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                    {{ number_format($var['variance'], 2) }}
                </td>
                <td class="text-right">
                    @if(($var['volumeVariance'] ?? 0) != 0)
                        <div class="text-amber-600">Vol: {{ number_format($var['volumeVariance'], 2) }}</div>
                    @endif
                    @if(($var['priceVariance'] ?? 0) != 0)
                        <div class="text-blue-600">Price: {{ number_format($var['priceVariance'], 2) }}</div>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-center text-gray-500 py-4 italic">No financial variances detected across the project scope.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Technical Analysis -->
    <div style="page-break-inside: avoid;">
        <div class="section-title">Audit methodology & Technical Notes</div>
        <div style="background-color: #f8fafc; padding: 10px; border: 1px solid #e2e8f0; font-size: 8px; color: #4b5563;">
            <p>1. <strong>Volume Variance:</strong> Calculated as (Current Qty - Baseline Qty) &times; Baseline Unit Price. Represents costs associated with scope changes.</p>
            <p>2. <strong>Price Variance:</strong> Calculated as (Current Unit Price - Baseline Unit Price) &times; Current Qty. Represents market inflation or negotiation impact.</p>
            <p>3. <strong>Identity Matching:</strong> Uses Persistent Component IDs to track changes even if descriptions or element groups are renamed.</p>
        </div>
    </div>

    <div class="footer">
        <p><strong>WOODNORK GREEN LTD - QUANTITY SURVEYING & FINANCIAL CONTROLS</strong></p>
        <p>This report is computer-generated and represents a snapshot of the budget lifecycle audit for project job #{{ $enquiry->job_number ?? 'N/A' }}.</p>
    </div>

</body>
</html>
