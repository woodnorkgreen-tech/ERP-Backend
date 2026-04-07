<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Inventory Movement Report - {{ now()->format('Y-m-d') }}</title>
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
        
        .text-blue-600 { color: #2563eb; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-900 { color: #111827; }
        .text-emerald-600 { color: #059669; }
        .text-rose-600 { color: #dc2626; }
        
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .mb-4 { margin-bottom: 15px; }
        
        .section-header {
            background-color: #f3f4f6;
            padding: 8px;
            border: 1px solid #d1d5db;
            font-size: 10px;
            border-radius: 2px;
            margin-bottom: 10px;
        }

        table { width: 100%; border-collapse: collapse; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 8px; }
        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            border-bottom: 2px solid #e2e8f0;
            text-transform: uppercase;
        }
        .data-table td {
            border-bottom: 1px solid #f1f5f9;
            padding: 6px 8px;
            vertical-align: middle;
        }
        
        .badge {
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-in { background-color: #ecfdf5; color: #059669; }
        .badge-out { background-color: #fef2f2; color: #dc2626; }
        .badge-return { background-color: #fffbeb; color: #b45309; }

        .footer {
            margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e7eb;
            text-align: center; color: #4b5563; font-size: 8px;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <table style="margin-bottom: 20px;">
        <tr>
            <td style="width: 50%;">
                <img src="{{ public_path('logo-outline.png') }}" style="height: 50px; width: auto; margin-bottom: 5px;" alt="Logo"/>
                <div class="font-bold text-gray-900 uppercase" style="font-size: 12px;">Woodnork Green</div>
            </td>
            <td style="width: 50%; text-align: right;">
                <h2 class="text-blue-600 uppercase tracking-wide" style="margin: 0 0 5px 0;">Inventory Movement Report</h2>
                <div class="text-gray-600">Generated on: {{ now()->format('d/M/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <!-- Filters Summary -->
    <div class="section-header">
        <table style="width: 100%;">
            <tr>
                <td style="width: 33%;">
                    <span class="font-bold">Period:</span> 
                    {{ $filters['start_date'] ?? 'All Time' }} to {{ $filters['end_date'] ?? 'Present' }}
                </td>
                <td style="width: 33%; text-align: center;">
                    <span class="font-bold">Type:</span> 
                    {{ strtoupper(str_replace('_', ' ', $filters['type'] ?? 'All Transactions')) }}
                </td>
                <td style="width: 33%; text-align: right;">
                    <span class="font-bold">Search:</span> 
                    {{ $filters['search'] ?? 'None' }}
                </td>
            </tr>
        </table>
    </div>

    <!-- Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 12%;">Ref / Batch</th>
                <th style="width: 10%;">Activity</th>
                <th style="width: 30%;">Material Item</th>
                <th style="width: 10%; text-align: center;">Qty</th>
                <th style="width: 18%;">Trans. Date</th>
                <th style="width: 20%; text-align: right;">Audit / Context</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
            <tr>
                <td class="font-bold" style="font-family: monospace;">{{ $log->batch_number }}</td>
                <td>
                    @php
                        $badgeClass = 'badge-in';
                        if($log->type === 'check_out') $badgeClass = 'badge-out';
                        if($log->type === 'return') $badgeClass = 'badge-return';
                    @endphp
                    <span class="badge {{ $badgeClass }}">{{ str_replace('_', ' ', $log->type) }}</span>
                </td>
                <td>
                    <div class="font-bold uppercase">{{ $log->material->material_name ?? 'N/A' }}</div>
                    <div class="text-gray-600" style="font-family: monospace; font-size: 7px;">{{ $log->material->material_code ?? '' }}</div>
                </td>
                <td class="text-center font-bold {{ $log->quantity > 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    {{ $log->quantity > 0 ? '+' : '' }}{{ $log->quantity }}
                    <span style="font-size: 7px; color: #6b7280; margin-left: 2px;">{{ $log->material->unit_of_measure ?? '' }}</span>
                </td>
                <td class="text-gray-600">{{ ($log->logged_at ?: $log->created_at)->format('d M Y, H:i') }}</td>
                <td class="text-right">
                    <div class="font-bold uppercase">{{ $log->project->project_id ?? 'STORES' }}</div>
                    <div class="text-gray-600" style="font-size: 7px;">Entered: {{ $log->created_at->format('d/m/Y H:i') }}</div>
                    <div class="text-gray-500" style="font-size: 6px;">By: {{ $log->user->name ?? 'SYSTEM' }}</div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-center" style="padding: 40px; color: #9ca3af;">No movement logs found for the selected criteria.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Footer -->
    <div class="footer">
        <p class="font-bold text-gray-900">Woodnork Green Ltd - Internal Inventory Report</p>
        <p>Karen Village, Ngong Road, Nairobi, Kenya | www.woodnorkgreen.co.ke</p>
    </div>

</body>
</html>
