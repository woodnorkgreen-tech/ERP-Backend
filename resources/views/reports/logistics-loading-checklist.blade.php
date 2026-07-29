<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Loading Checklist — {{ $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? $data['task_id'] }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10px; color: #111827; margin: 0; padding: 0; line-height: 1.4; }
        @page { margin: 0.45in; }

        .font-bold   { font-weight: bold; }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        .doc-title { font-size: 18px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #0f172a; margin: 0; }
        .doc-sub   { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }

        .section-header {
            background-color: #0f172a; color: #fff;
            padding: 5px 10px; font-size: 9px; font-weight: bold;
            text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 0;
        }

        /* Info grid */
        .info-grid { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; margin-bottom: 14px; }
        .info-grid td { padding: 6px 10px; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; vertical-align: top; font-size: 9px; }
        .info-grid .lbl { color: #64748b; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 2px; }
        .info-grid .val { font-weight: bold; color: #0f172a; }

        /* Checklist table */
        .cl-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 0; }
        .cl-table th {
            background-color: #f8fafc; color: #475569;
            font-weight: bold; text-align: left; padding: 5px 8px;
            border-bottom: 1.5px solid #e2e8f0; text-transform: uppercase; font-size: 8px;
        }
        .cl-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .cl-table tr.alt-row { background-color: #f8fafc; }

        /* Category header row */
        .cat-row-production      td { background-color: #1e3a8a; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }
        .cat-row-tools           td { background-color: #78350f; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }
        .cat-row-electricals     td { background-color: #713f12; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }
        .cat-row-stores          td { background-color: #064e3b; color: #fff; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; border-bottom: none; }

        /* Item row tints per category */
        .tint-production  { background-color: #eff6ff; }
        .tint-tools       { background-color: #fffbeb; }
        .tint-electricals { background-color: #fefce8; }
        .tint-stores      { background-color: #f0fdf4; }

        /* Status badges */
        .badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 7px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-present  { background-color: #d1fae5; color: #065f46; }
        .badge-later    { background-color: #fef3c7; color: #92400e; }
        .badge-missing  { background-color: #fee2e2; color: #991b1b; }

        /* Sub-type chip */
        .subtype { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; text-transform: uppercase; background-color: #d1fae5; color: #065f46; }
        .subtype-consumable { background-color: #f1f5f9; color: #475569; }

        /* Checkbox */
        .chk { display: inline-block; width: 11px; height: 11px; border: 1.5px solid #94a3b8; border-radius: 2px; margin-right: 4px; vertical-align: middle; position: relative; }
        .chk.yes { background-color: #1d4ed8; border-color: #1d4ed8; }
        .chk.yes:after { content: '✓'; color: white; font-size: 8px; position: absolute; top: -2px; left: 1px; }

        /* Gate card */
        .gate-card { border: 1px solid #e2e8f0; border-radius: 3px; overflow: hidden; }
        .gate-card-head { background-color: #f8fafc; padding: 5px 8px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; border-bottom: 1px solid #e2e8f0; }
        .gate-item { padding: 4px 8px; font-size: 9px; border-bottom: 1px solid #f8fafc; }

        /* Totals strip */
        .totals-strip { background-color: #0f172a; padding: 6px 10px; margin-bottom: 14px; }
        .totals-strip td { color: white; font-size: 10px; padding: 0 16px 0 0; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 8px; font-weight: bold; }
        .pill-green  { background-color: #059669; }
        .pill-amber  { background-color: #d97706; }
        .pill-red    { background-color: #dc2626; }
        .pill-blue   { background-color: #2563eb; }
        .pill-yellow { background-color: #ca8a04; }
        .pill-emerald { background-color: #059669; }

        /* Sig */
        .sig-box { border: 1px solid #cbd5e1; height: 55px; background-color: #f9fafb; margin-top: 4px; }

        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; text-align: center; color: #64748b; font-size: 8px; }

        .wrapper { border: 1px solid #e2e8f0; margin-bottom: 14px; overflow: hidden; }
    </style>
</head>
<body>

@php
    $planning      = $data['logistics_planning'] ?? [];
    $checklist     = $data['checklist'] ?? [];
    $checkItems    = $checklist['items'] ?? [];
    $safety        = $checklist['safety']    ?? [];
    $equipment     = $checklist['equipment'] ?? [];
    $transportItems = $data['transport_items'] ?? [];

    // Build a name→status lookup from checklist items
    $statusByName = [];
    $notesByName  = [];
    foreach ($checkItems as $ci) {
        $statusByName[strtolower(trim($ci['item_name'] ?? ''))] = $ci['status'] ?? 'missing';
        $notesByName[strtolower(trim($ci['item_name'] ?? ''))]  = $ci['notes']  ?? '';
    }

    // Group transport items by category
    $categories = [
        'PRODUCTION'      => ['label' => 'Production Items',    'icon' => '◆', 'tint' => 'tint-production',  'cat_row' => 'cat-row-production'],
        'TOOLS_EQUIPMENTS'=> ['label' => 'Tools & Equipment',   'icon' => '⚙', 'tint' => 'tint-tools',       'cat_row' => 'cat-row-tools'],
        'ELECTRICALS'     => ['label' => 'Electricals',         'icon' => '⚡', 'tint' => 'tint-electricals', 'cat_row' => 'cat-row-electricals'],
        'STORES'          => ['label' => 'Stores',              'icon' => '▪', 'tint' => 'tint-stores',      'cat_row' => 'cat-row-stores'],
    ];

    $grouped = [];
    foreach ($transportItems as $item) {
        $cat = $item['main_category'] ?? 'PRODUCTION';
        $grouped[$cat][] = $item;
    }

    // Overall totals from checklist
    $presentCount = count(array_filter($checkItems, fn($i) => ($i['status'] ?? '') === 'present'));
    $laterCount   = count(array_filter($checkItems, fn($i) => ($i['status'] ?? '') === 'coming_later'));
    $missingCount = count(array_filter($checkItems, fn($i) => ($i['status'] ?? '') === 'missing'));
    $totalCount   = count($checkItems) ?: count($transportItems);

    // Per-category unit totals
    $catTotals = [];
    foreach ($grouped as $cat => $items) {
        $catTotals[$cat] = array_sum(array_column($items, 'quantity'));
    }
    $grandTotal = array_sum(array_column($transportItems, 'quantity'));
@endphp

<!-- Header -->
<table style="width: 100%; margin-bottom: 16px;">
    <tr>
        <td style="width: 55%;">
            <img src="{{ public_path('logo-outline.png') }}" style="height: 55px; width: auto; margin-bottom: 4px; display: block;" alt="Logo"/>
            <div style="font-size: 13px; font-weight: bold; text-transform: uppercase; color: #0f172a; letter-spacing: 1px;">Woodnork Green</div>
        </td>
        <td style="width: 45%; text-align: right; vertical-align: top;">
            <div class="doc-title">Loading Checklist</div>
            <div class="doc-sub">Dispatch Gate Check — Items to Load</div>
            <div style="margin-top: 8px; font-size: 9px; color: #475569;">
                Job: <strong style="color: #0f172a;">{{ $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? '—' }}</strong>
                &nbsp;|&nbsp;
                Date: <strong style="color: #0f172a;">{{ now()->format('d M Y') }}</strong>
            </div>
        </td>
    </tr>
</table>

<!-- Dispatch info -->
<div class="section-header">Dispatch Details</div>
<table class="info-grid" style="margin-bottom: 14px;">
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
            <span class="lbl">Destination</span>
            <span class="val">{{ $planning['route']['destination'] ?? 'TBC' }}</span>
        </td>
        <td style="width: 25%; border-right: none;">
            <span class="lbl">Delivery Date</span>
            <span class="val">
                @if(!empty($planning['timeline']['setup_start_time']))
                    {{ \Carbon\Carbon::parse($planning['timeline']['setup_start_time'])->format('d M Y') }}
                @else TBC @endif
            </span>
        </td>
    </tr>
    <tr>
        <td>
            <span class="lbl">Transport</span>
            <span class="val">{{ ($planning['transport_arrangement'] ?? 'company') === 'client' ? 'Client provided' : 'Company provided' }}</span>
        </td>
        <td>
            <span class="lbl">Vehicle Reg.</span>
            <span class="val">{{ ($planning['transport_arrangement'] ?? 'company') === 'client' ? 'Provided by client' : ($planning['vehicle_identification'] ?? '—') }}</span>
        </td>
        <td>
            <span class="lbl">Team Captain</span>
            <span class="val">{{ $planning['team_captain'] ?? '—' }}</span>
        </td>
        <td style="border-right: none;">
            <span class="lbl">Loading Time</span>
            <span class="val">{{ $planning['timeline']['loading_time'] ?? '—' }}</span>
        </td>
    </tr>
</table>

<!-- Summary strip -->
<table class="totals-strip" style="width: 100%; border-collapse: collapse; margin-bottom: 14px;">
    <tr>
        <td style="font-weight: bold;">{{ $grandTotal }} units across {{ count($grouped) }} categories</td>
        @foreach($catTotals as $cat => $qty)
        <td>
            <span class="pill
                @if($cat==='PRODUCTION') pill-blue
                @elseif($cat==='TOOLS_EQUIPMENTS') pill-amber
                @elseif($cat==='ELECTRICALS') pill-yellow
                @else pill-emerald @endif">
                {{ $qty }} {{ $categories[$cat]['label'] ?? $cat }}
            </span>
        </td>
        @endforeach
        @if(count($checkItems))
        <td style="text-align: right; padding-right: 0; color: #94a3b8; font-size: 8px;">
            Checklist: <span style="color: #6ee7b7;">{{ $presentCount }}✓</span>
            <span style="color: #fcd34d;"> {{ $laterCount }}⏳</span>
            <span style="color: #fca5a5;"> {{ $missingCount }}✗</span>
        </td>
        @endif
    </tr>
</table>

<!-- Categorized item tables -->
@if(count($transportItems) > 0)
@foreach($categories as $catKey => $catMeta)
@if(isset($grouped[$catKey]) && count($grouped[$catKey]) > 0)
<div class="wrapper">
    <table class="cl-table">
        <thead>
            <tr class="{{ $catMeta['cat_row'] }}">
                <td colspan="5">{{ $catMeta['icon'] }} &nbsp; {{ $catMeta['label'] }}
                    &nbsp;—&nbsp; {{ count($grouped[$catKey]) }} item(s),
                    {{ array_sum(array_column($grouped[$catKey], 'quantity')) }} units
                </td>
            </tr>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 46%;">Description</th>
                <th style="width: 10%; text-align: center;">Qty</th>
                <th style="width: 14%; text-align: center;">Status</th>
                <th style="width: 26%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($grouped[$catKey] as $rowIdx => $item)
            @php
                $key    = strtolower(trim($item['name'] ?? ''));
                $st     = $statusByName[$key] ?? 'missing';
                $note   = $notesByName[$key]  ?? ($item['description'] ?? '');
                $isEven = $rowIdx % 2 === 0;
            @endphp
            <tr class="{{ !count($checkItems) || $isEven ? '' : $catMeta['tint'] }}">
                <td class="text-center" style="color: #94a3b8;">{{ $rowIdx + 1 }}</td>
                <td>
                    <span style="font-weight: bold; color: #0f172a;">{{ $item['name'] ?? '—' }}</span>
                    @if(($item['sub_type'] ?? '') === 'hire')
                        <span class="subtype" style="margin-left: 4px;">↩ Hire</span>
                    @elseif(($item['sub_type'] ?? '') === 'consumable')
                        <span class="subtype subtype-consumable" style="margin-left: 4px;">Consumable</span>
                    @endif
                    @if(($item['is_returnable'] ?? false) && ($item['sub_type'] ?? '') !== 'hire')
                        <span style="font-size: 7px; color: #2563eb; margin-left: 3px;">↩ Returns</span>
                    @endif
                </td>
                <td class="text-center font-bold">{{ $item['quantity'] ?? 0 }} <span style="font-weight: normal; color: #94a3b8; font-size: 8px;">{{ $item['unit'] ?? '' }}</span></td>
                <td class="text-center">
                    @if(count($checkItems))
                        @if($st === 'present')
                            <span class="badge badge-present">Present</span>
                        @elseif($st === 'coming_later')
                            <span class="badge badge-later">Later</span>
                        @else
                            <span class="badge badge-missing">—</span>
                        @endif
                    @else
                        <span style="color: #cbd5e1; font-size: 8px;">Not checked</span>
                    @endif
                </td>
                <td style="color: #475569; font-size: 8px;">{{ $note }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endforeach
@else
<div style="padding: 18px; text-align: center; color: #94a3b8; font-size: 9px; border: 1px solid #e2e8f0; margin-bottom: 14px;">
    No loading sheet items added yet.
</div>
@endif

<!-- Gate checks -->
<table style="width: 100%; border-collapse: collapse; margin-bottom: 14px;">
    <tr>
        <td style="width: 32%; padding-right: 8px; vertical-align: top;">
            <div class="gate-card">
                <div class="gate-card-head">Safety Gear</div>
                <div class="gate-item"><span class="chk {{ ($safety['ppe'] ?? false) ? 'yes' : '' }}"></span> PPE (Vests, Helmets, Boots)</div>
                <div class="gate-item"><span class="chk {{ ($safety['first_aid'] ?? false) ? 'yes' : '' }}"></span> First Aid Kit</div>
                <div class="gate-item" style="border-bottom: none;"><span class="chk {{ ($safety['fire_extinguisher'] ?? false) ? 'yes' : '' }}"></span> Fire Extinguisher</div>
            </div>
        </td>
        <td style="width: 32%; padding-right: 8px; vertical-align: top;">
            <div class="gate-card">
                <div class="gate-card-head">Equipment & Vehicles</div>
                <div class="gate-item"><span class="chk {{ ($equipment['tools'] ?? false) ? 'yes' : '' }}"></span> Tool Kits</div>
                <div class="gate-item"><span class="chk {{ ($equipment['vehicles'] ?? false) ? 'yes' : '' }}"></span> Vehicle Inspection</div>
                <div class="gate-item" style="border-bottom: none;"><span class="chk {{ ($equipment['communication'] ?? false) ? 'yes' : '' }}"></span> Comms (Radios)</div>
            </div>
        </td>
        <td style="width: 36%; vertical-align: top;">
            <div class="gate-card">
                <div class="gate-card-head">Departure Schedule</div>
                <div class="gate-item"><span style="color: #64748b;">Loading:</span> <strong>{{ $planning['timeline']['loading_time'] ?? '—' }}</strong></div>
                <div class="gate-item"><span style="color: #64748b;">Departure:</span> <strong>{{ $planning['timeline']['departure_time'] ?? '—' }}</strong></div>
                <div class="gate-item" style="border-bottom: none;"><span style="color: #64748b;">Crew Vehicle:</span> <strong>{{ $planning['crew_vehicle'] ?? '—' }}</strong></div>
            </div>
        </td>
    </tr>
</table>

<!-- Sign-off -->
<div class="section-header" style="margin-bottom: 8px;">Sign-off — Loaded & Ready to Depart</div>
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="width: 33%; padding-right: 10px;">
            <div style="font-size: 9px; color: #475569; text-transform: uppercase; font-weight: bold; text-align: center; margin-bottom: 3px;">Loading Supervisor</div>
            <div class="sig-box"></div>
            <div style="text-align: center; margin-top: 3px; font-size: 8px; color: #475569;">Signature / Date: ___________</div>
        </td>
        <td style="width: 33%; padding-right: 10px;">
            <div style="font-size: 9px; color: #475569; text-transform: uppercase; font-weight: bold; text-align: center; margin-bottom: 3px;">{{ ($planning['transport_arrangement'] ?? 'company') === 'client' ? 'Client Driver' : 'Driver — '.($planning['driver_name'] ?? '______') }}</div>
            <div class="sig-box"></div>
            <div style="text-align: center; margin-top: 3px; font-size: 8px; color: #475569;">Signature / Date: ___________</div>
        </td>
        <td style="width: 33%;">
            <div style="font-size: 9px; color: #475569; text-transform: uppercase; font-weight: bold; text-align: center; margin-bottom: 3px;">Security / Gate</div>
            <div class="sig-box"></div>
            <div style="text-align: center; margin-top: 3px; font-size: 8px; color: #475569;">Signature / Time: ___________</div>
        </td>
    </tr>
</table>

<div class="footer">
    <strong>Woodnork Green Ltd</strong> &nbsp;|&nbsp; Tel: +254 780 397 798 &nbsp;|&nbsp; admin@woodnorkgreen.co.ke &nbsp;|&nbsp; Karen Village, Ngong Road, Nairobi
</div>

</body>
</html>
