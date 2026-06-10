<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Return Checklist — {{ $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? $data['task_id'] }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10px; color: #111827; margin: 0; padding: 0; line-height: 1.4; }
        @page { margin: 0.45in; }

        .font-bold  { font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .doc-title { font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #0f172a; margin: 0; }
        .doc-sub   { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }

        .section-header {
            background-color: #78350f; color: #fff;
            padding: 5px 10px; font-size: 9px; font-weight: bold;
            text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 0;
        }

        /* Info grid */
        .info-grid { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; margin-bottom: 14px; }
        .info-grid td { padding: 6px 10px; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; vertical-align: top; font-size: 9px; }
        .info-grid .lbl { color: #64748b; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 2px; }
        .info-grid .val { font-weight: bold; color: #0f172a; }

        /* Return table */
        .rt-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 0; }
        .rt-table th {
            background-color: #f8fafc; color: #475569;
            font-weight: bold; text-align: left; padding: 5px 8px;
            border-bottom: 1.5px solid #e2e8f0; text-transform: uppercase; font-size: 8px;
        }
        .rt-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }

        /* Category header rows */
        .cat-row-production      td { background-color: #1e3a8a; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }
        .cat-row-tools           td { background-color: #78350f; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }
        .cat-row-electricals     td { background-color: #713f12; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }
        .cat-row-stores          td { background-color: #064e3b; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }

        /* Row tints */
        .tint-production  { background-color: #eff6ff; }
        .tint-tools       { background-color: #fffbeb; }
        .tint-electricals { background-color: #fefce8; }
        .tint-stores      { background-color: #f0fdf4; }

        /* Status badges */
        .badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .badge-returned { background-color: #d1fae5; color: #065f46; }
        .badge-partial  { background-color: #fef3c7; color: #92400e; }
        .badge-pending  { background-color: #f1f5f9; color: #475569; }
        .badge-damaged  { background-color: #fee2e2; color: #991b1b; }
        .badge-missing  { background-color: #ffe4e6; color: #9f1239; }

        /* Condition dot */
        .cond { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 3px; vertical-align: middle; }
        .cond-good    { background-color: #10b981; }
        .cond-worn    { background-color: #f59e0b; }
        .cond-damaged { background-color: #ef4444; }

        /* Summary strip */
        .summary-strip { background-color: #78350f; padding: 6px 10px; margin-bottom: 14px; }
        .summary-strip td { color: white; font-size: 10px; padding: 0 16px 0 0; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 8px; font-weight: bold; }
        .pill-green  { background-color: #059669; }
        .pill-amber  { background-color: #d97706; }
        .pill-red    { background-color: #dc2626; }
        .pill-blue   { background-color: #2563eb; }
        .pill-yellow { background-color: #ca8a04; }
        .pill-emerald { background-color: #059669; }

        /* Auth panel */
        .auth-panel { border: 1.5px solid #d1fae5; background-color: #f0fdf4; padding: 10px 12px; border-radius: 3px; margin-bottom: 14px; }
        .auth-pending { border-color: #fef3c7; background-color: #fffbeb; }

        /* Damage box */
        .damage-box { border: 1.5px solid #fee2e2; background-color: #fff1f2; padding: 8px 12px; border-radius: 3px; margin-bottom: 14px; }

        /* Sig */
        .sig-box { border: 1px solid #cbd5e1; height: 55px; background-color: #f9fafb; margin-top: 4px; }

        .wrapper { border: 1px solid #e2e8f0; margin-bottom: 14px; overflow: hidden; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; text-align: center; color: #64748b; font-size: 8px; }
    </style>
</head>
<body>

@php
    $planning    = $data['logistics_planning'] ?? [];
    $checklist   = $data['checklist'] ?? [];
    $returnItems = $checklist['return_items'] ?? [];

    $categories = [
        'PRODUCTION'       => ['label' => 'Production Items',    'icon' => '◆', 'tint' => 'tint-production',  'cat_row' => 'cat-row-production',  'pill' => 'pill-blue'],
        'TOOLS_EQUIPMENTS' => ['label' => 'Tools & Equipment',   'icon' => '⚙', 'tint' => 'tint-tools',       'cat_row' => 'cat-row-tools',        'pill' => 'pill-amber'],
        'ELECTRICALS'      => ['label' => 'Electricals',         'icon' => '⚡', 'tint' => 'tint-electricals', 'cat_row' => 'cat-row-electricals',  'pill' => 'pill-yellow'],
        'STORES'           => ['label' => 'Stores',              'icon' => '▪', 'tint' => 'tint-stores',      'cat_row' => 'cat-row-stores',       'pill' => 'pill-emerald'],
    ];

    // Group return items by category
    $grouped = [];
    foreach ($returnItems as $item) {
        $cat = $item['main_category'] ?? 'PRODUCTION';
        $grouped[$cat][] = $item;
    }

    // Overall counts
    $returnedCount   = count(array_filter($returnItems, fn($i) => ($i['status'] ?? '') === 'returned'));
    $pendingCount    = count(array_filter($returnItems, fn($i) => in_array($i['status'] ?? '', ['pending', 'partial'])));
    $damagedCount    = count(array_filter($returnItems, fn($i) => ($i['condition'] ?? 'good') !== 'good' || ($i['status'] ?? '') === 'missing'));
    $totalCount      = count($returnItems);

    $authorized   = $checklist['return_authorized']    ?? false;
    $authorizedAt = $checklist['return_authorized_at'] ?? null;

    $dispatchedUnits = array_sum(array_column($returnItems, 'quantity_dispatched'));
    $returnedUnits   = array_sum(array_column($returnItems, 'quantity_returned'));

    $flaggedItems = array_filter($returnItems, fn($i) => ($i['condition'] ?? 'good') !== 'good' || ($i['status'] ?? '') === 'missing');
@endphp

<!-- Header -->
<table style="width: 100%; margin-bottom: 16px;">
    <tr>
        <td style="width: 55%;">
            <img src="{{ public_path('logo-outline.png') }}" style="height: 55px; width: auto; margin-bottom: 4px; display: block;" alt="Logo"/>
            <div style="font-size: 13px; font-weight: bold; text-transform: uppercase; color: #0f172a; letter-spacing: 1px;">Woodnork Green</div>
        </td>
        <td style="width: 45%; text-align: right; vertical-align: top;">
            <div class="doc-title">Return Checklist</div>
            <div class="doc-sub">Setdown Gate Check — Items Returning to Warehouse</div>
            <div style="margin-top: 8px; font-size: 9px; color: #475569;">
                Job: <strong style="color: #0f172a;">{{ $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? '—' }}</strong>
                &nbsp;|&nbsp;
                Printed: <strong style="color: #0f172a;">{{ now()->format('d M Y') }}</strong>
            </div>
        </td>
    </tr>
</table>

<!-- Setdown details -->
<div class="section-header">Setdown Details</div>
<table class="info-grid">
    <tr>
        <td style="width: 25%;">
            <span class="lbl">Project</span>
            <span class="val">{{ $task->enquiry->title ?? 'N/A' }}</span>
        </td>
        <td style="width: 25%;">
            <span class="lbl">Client</span>
            <span class="val">{{ $client->full_name ?? $client->name ?? 'N/A' }}</span>
        </td>
        <td style="width: 25%;">
            <span class="lbl">Setdown Date</span>
            <span class="val">
                @if(!empty($planning['timeline']['setdown_date']))
                    {{ \Carbon\Carbon::parse($planning['timeline']['setdown_date'])->format('d M Y') }}
                @else TBC @endif
            </span>
        </td>
        <td style="width: 25%; border-right: none;">
            <span class="lbl">Setdown Time</span>
            <span class="val">{{ $planning['timeline']['setdown_time'] ?? 'TBC' }}</span>
        </td>
    </tr>
    <tr>
        <td>
            <span class="lbl">Driver</span>
            <span class="val">{{ $planning['driver_name'] ?? '—' }}</span>
        </td>
        <td>
            <span class="lbl">Vehicle Reg.</span>
            <span class="val">{{ $planning['vehicle_identification'] ?? '—' }}</span>
        </td>
        <td>
            <span class="lbl">Return Status</span>
            <span class="val" style="color: {{ $authorized ? '#059669' : '#d97706' }};">
                {{ $authorized ? 'Authorized' : 'Pending' }}
            </span>
        </td>
        <td style="border-right: none;">
            <span class="lbl">Units Returned</span>
            <span class="val">{{ $returnedUnits }} / {{ $dispatchedUnits }}</span>
        </td>
    </tr>
</table>

<!-- Summary strip -->
<table class="summary-strip" style="width: 100%; border-collapse: collapse; margin-bottom: 14px;">
    <tr>
        <td style="font-weight: bold;">{{ $totalCount }} returnable items</td>
        <td><span class="pill pill-green">{{ $returnedCount }} returned</span></td>
        <td><span class="pill pill-amber">{{ $pendingCount }} outstanding</span></td>
        <td><span class="pill pill-red">{{ $damagedCount }} damaged/missing</span></td>
        <td style="text-align: right; padding-right: 0; color: #fcd34d; font-size: 8px;">{{ now()->format('d M Y H:i') }}</td>
    </tr>
</table>

<!-- Categorized return items -->
@if(count($returnItems) > 0)

@foreach($categories as $catKey => $catMeta)
@if(isset($grouped[$catKey]) && count($grouped[$catKey]) > 0)
@php
    $catItems        = $grouped[$catKey];
    $catDispatched   = array_sum(array_column($catItems, 'quantity_dispatched'));
    $catReturned     = array_sum(array_column($catItems, 'quantity_returned'));
    $catReturnedPcs  = count(array_filter($catItems, fn($i) => ($i['status'] ?? '') === 'returned'));
    $catPending      = count(array_filter($catItems, fn($i) => in_array($i['status'] ?? '', ['pending', 'partial'])));
@endphp
<div class="wrapper">
    <table class="rt-table">
        <thead>
            <tr class="{{ $catMeta['cat_row'] }}">
                <td colspan="7">
                    {{ $catMeta['icon'] }} &nbsp; {{ $catMeta['label'] }}
                    &nbsp;—&nbsp;
                    {{ count($catItems) }} item(s) &nbsp;|&nbsp;
                    {{ $catReturned }}/{{ $catDispatched }} units returned
                    @if($catPending > 0)&nbsp;|&nbsp; {{ $catPending }} outstanding @endif
                </td>
            </tr>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 32%;">Item</th>
                <th style="width: 12%; text-align: center;">Dispatched</th>
                <th style="width: 12%; text-align: center;">Returned</th>
                <th style="width: 13%; text-align: center;">Condition</th>
                <th style="width: 11%; text-align: center;">Status</th>
                <th style="width: 16%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($catItems as $rowIdx => $item)
            @php
                $status    = $item['status']    ?? 'pending';
                $condition = $item['condition'] ?? 'good';
                $isDamaged = $condition !== 'good' || $status === 'missing';
                $rowBg     = $isDamaged ? 'background-color: #fff1f2;' : ($rowIdx % 2 !== 0 ? '' : '');
                $tint      = !$isDamaged && $rowIdx % 2 === 1 ? $catMeta['tint'] : '';
            @endphp
            <tr class="{{ $tint }}" style="{{ $isDamaged ? 'background-color: #fff1f2;' : '' }}">
                <td class="text-center" style="color: #94a3b8;">{{ $rowIdx + 1 }}</td>
                <td style="font-weight: bold; color: #0f172a;">{{ $item['name'] ?? '—' }}</td>
                <td class="text-center font-bold" style="color: #475569;">
                    {{ $item['quantity_dispatched'] ?? 0 }}
                    <span style="font-weight: normal; color: #94a3b8; font-size: 8px;">{{ $item['unit'] ?? '' }}</span>
                </td>
                <td class="text-center font-bold" style="color:
                    @if(($item['quantity_returned'] ?? 0) >= ($item['quantity_dispatched'] ?? 0)) #059669
                    @elseif(($item['quantity_returned'] ?? 0) > 0) #d97706
                    @else #94a3b8 @endif;">
                    {{ $item['quantity_returned'] ?? 0 }}
                </td>
                <td class="text-center">
                    <span class="cond cond-{{ $condition }}"></span>{{ ucfirst($condition) }}
                </td>
                <td class="text-center">
                    @if($status === 'returned')     <span class="badge badge-returned">Returned</span>
                    @elseif($status === 'partial')  <span class="badge badge-partial">Partial</span>
                    @elseif($status === 'damaged')  <span class="badge badge-damaged">Damaged</span>
                    @elseif($status === 'missing')  <span class="badge badge-missing">Missing</span>
                    @else                           <span class="badge badge-pending">Pending</span>
                    @endif
                </td>
                <td style="color: #475569; font-size: 8px;">{{ $item['notes'] ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endforeach

@else
<div style="padding: 18px; text-align: center; color: #94a3b8; font-size: 9px; border: 1px solid #e2e8f0; margin-bottom: 14px;">
    No return items loaded. Generate the return checklist from the manifest first.
</div>
@endif

<!-- Damage / missing report -->
@if(count($flaggedItems) > 0)
<div class="damage-box">
    <div style="font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #991b1b; margin-bottom: 6px;">
        Damage &amp; Missing Report — Flagged for Storekeeper
    </div>
    @foreach($flaggedItems as $item)
    <div style="font-size: 9px; color: #7f1d1d; margin-bottom: 3px;">
        &#8226;
        <strong>{{ $item['name'] }}</strong>
        <span style="color: #94a3b8; font-size: 8px;">[{{ str_replace('_', ' ', $item['main_category'] ?? '') }}]</span>
        — Condition: {{ ucfirst($item['condition'] ?? 'good') }}, Status: {{ ucfirst($item['status'] ?? 'pending') }}
        @if(!empty($item['notes'])) <span style="color: #b91c1c;">({{ $item['notes'] }})</span>@endif
    </div>
    @endforeach
</div>
@endif

<!-- Authorization status -->
@if($authorized && $authorizedAt)
<div class="auth-panel" style="margin-bottom: 14px;">
    <div style="font-size: 9px; font-weight: bold; text-transform: uppercase; color: #065f46; margin-bottom: 3px;">
        ✓ Return Authorized — All Items Accounted For
    </div>
    <div style="font-size: 9px; color: #047857;">
        Authorized on {{ \Carbon\Carbon::parse($authorizedAt)->format('d M Y \a\t H:i') }}
        @if(!empty($checklist['setdown_notes']))<br/>Notes: {{ $checklist['setdown_notes'] }}@endif
    </div>
</div>
@else
<div class="auth-panel auth-pending" style="margin-bottom: 14px;">
    <div style="font-size: 9px; color: #92400e;">
        Return not yet authorized.
        {{ $pendingCount > 0 ? $pendingCount . ' item(s) still outstanding.' : 'All items accounted for — ready to authorize.' }}
    </div>
</div>
@endif

<!-- Sign-off -->
<div class="section-header" style="margin-bottom: 8px;">Sign-off — Setdown Confirmation</div>
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="width: 33%; padding-right: 10px;">
            <div style="font-size: 9px; color: #475569; text-transform: uppercase; font-weight: bold; text-align: center; margin-bottom: 3px;">Setdown Supervisor</div>
            <div class="sig-box"></div>
            <div style="text-align: center; margin-top: 3px; font-size: 8px; color: #475569;">Signature / Date: ___________</div>
        </td>
        <td style="width: 33%; padding-right: 10px;">
            <div style="font-size: 9px; color: #475569; text-transform: uppercase; font-weight: bold; text-align: center; margin-bottom: 3px;">Driver — {{ $planning['driver_name'] ?? '______' }}</div>
            <div class="sig-box"></div>
            <div style="text-align: center; margin-top: 3px; font-size: 8px; color: #475569;">Signature / Date: ___________</div>
        </td>
        <td style="width: 33%;">
            <div style="font-size: 9px; color: #475569; text-transform: uppercase; font-weight: bold; text-align: center; margin-bottom: 3px;">Stores Officer</div>
            <div class="sig-box"></div>
            <div style="text-align: center; margin-top: 3px; font-size: 8px; color: #475569;">Signature / Date: ___________</div>
        </td>
    </tr>
</table>

<div class="footer">
    <strong>Woodnork Green Ltd</strong> &nbsp;|&nbsp; Tel: +254 780 397 798 &nbsp;|&nbsp; admin@woodnorkgreen.co.ke &nbsp;|&nbsp; Karen Village, Ngong Road, Nairobi
</div>

</body>
</html>
