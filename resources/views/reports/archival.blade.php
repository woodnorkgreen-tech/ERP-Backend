<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Project Closure - {{ $report->project_code ?: $report->id }}</title>
    <style>
        @page { margin: 34px 38px 46px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.45; }
        .footer { position: fixed; right: 0; bottom: -28px; left: 0; border-top: 1px solid #dbe2ea; padding-top: 7px; color: #718096; font-size: 7px; }
        .page:after { content: counter(page); }
        .header { border-bottom: 2px solid #172033; padding-bottom: 12px; margin-bottom: 14px; }
        .brand { font-size: 15px; font-weight: bold; letter-spacing: .4px; }
        .document-title { text-align: right; font-size: 17px; font-weight: bold; letter-spacing: .5px; }
        .muted { color: #718096; }
        .small { font-size: 7px; }
        .status { display: inline-block; padding: 4px 9px; border-radius: 10px; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .approved { color: #126b49; background: #def7ec; }
        .submitted { color: #8a4b08; background: #fff3cd; }
        .draft { color: #4a5568; background: #edf2f7; }
        .section { margin-top: 13px; page-break-inside: avoid; }
        .section-title { border-bottom: 1px solid #cbd5e0; padding-bottom: 4px; margin-bottom: 7px; color: #334155; font-size: 8px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .identity td { width: 25%; padding: 5px 8px 5px 0; }
        .label { color: #718096; font-size: 7px; font-weight: bold; letter-spacing: .4px; text-transform: uppercase; }
        .value { margin-top: 2px; font-weight: bold; }
        .scope { border-left: 3px solid #2563eb; background: #f7fafc; padding: 8px 10px; }
        .metrics td { width: 33.333%; border: 1px solid #dbe2ea; padding: 9px; }
        .amount { margin-top: 3px; font-size: 13px; font-weight: bold; }
        .positive { color: #087f5b; }
        .warning { color: #b45309; }
        .note { margin-top: 6px; border: 1px solid #f6d58d; background: #fffaf0; padding: 6px 8px; color: #8a4b08; }
        .data th { background: #eef2f7; padding: 5px 7px; color: #4a5568; font-size: 7px; text-align: left; text-transform: uppercase; }
        .data td { border-bottom: 1px solid #e5eaf0; padding: 5px 7px; }
        .check { color: #087f5b; font-weight: bold; }
        .missing { color: #c53030; font-weight: bold; }
        .text-box { min-height: 34px; border: 1px solid #dbe2ea; background: #f8fafc; padding: 7px 8px; white-space: pre-wrap; }
        .approval td { width: 50%; border: 1px solid #dbe2ea; padding: 9px; }
        .approval-name { margin-top: 5px; font-size: 11px; font-weight: bold; }
        .record { border: 1px solid #cbd5e0; background: #f8fafc; padding: 8px; }
    </style>
</head>
<body>
@php
    $checks = [
        'Site survey filed' => $report->checklist_site_survey_form,
        'Approved budget filed' => $report->checklist_project_budget_file,
        'Material list filed' => $report->checklist_material_list,
        'Quality checks complete' => $report->checklist_qc_checklist,
        'Setup and return closed' => $report->checklist_setup_setdown,
        'Client handover / feedback filed' => $report->checklist_client_feedback,
    ];
    $verified = collect($checks)->filter()->count();
    $finance = $financialSummary ?? [];
    $docs = $systemDocuments ?? [];
    $status = $report->status ?: 'draft';
@endphp

<div class="footer">
    <table><tr><td>Woodnork Green · Project closure record · {{ $report->archive_reference ?: 'Reference pending' }}</td><td style="text-align:right">Generated {{ now()->format('d M Y H:i') }} · Page <span class="page"></span></td></tr></table>
</div>

<table class="header"><tr>
    <td style="width:45%"><div class="brand">WOODNORK GREEN</div><div class="muted small">Project delivery and archival control</div></td>
    <td style="width:55%"><div class="document-title">PROJECT CLOSURE REPORT</div><div style="text-align:right;margin-top:4px"><span class="status {{ $status }}">{{ $status }}</span></div></td>
</tr></table>

<table class="identity"><tr>
    <td><div class="label">Project code</div><div class="value">{{ $report->project_code ?: 'Not assigned' }}</div></td>
    <td><div class="label">Client</div><div class="value">{{ $report->client_name ?: 'Not linked' }}</div></td>
    <td><div class="label">Project officer</div><div class="value">{{ $report->project_officer ?: 'Not assigned' }}</div></td>
    <td><div class="label">Site</div><div class="value">{{ $report->site_location ?: 'Not recorded' }}</div></td>
</tr><tr>
    <td><div class="label">Project period</div><div class="value">{{ $report->start_date?->format('d M Y') ?: '—' }} to {{ $report->end_date?->format('d M Y') ?: '—' }}</div></td>
    <td><div class="label">Archive reference</div><div class="value">{{ $report->archive_reference ?: 'Pending' }}</div></td>
    <td colspan="2"><div class="label">Archive location</div><div class="value">{{ $report->archive_location ?: 'Pending' }}</div></td>
</tr></table>

<div class="section">
    <div class="section-title">Delivered scope</div>
    <div class="scope">{!! nl2br(e($report->project_scope ?: 'No project scope was recorded.')) !!}</div>
</div>

<div class="section">
    <div class="section-title">Finance reconciliation</div>
    <table class="metrics"><tr>
        <td><div class="label">Approved quote</div><div class="amount">KES {{ number_format($finance['approved_quote'] ?? 0, 2) }}</div></td>
        <td><div class="label">Payments received</div><div class="amount positive">KES {{ number_format($finance['payments_received'] ?? 0, 2) }}</div></td>
        <td><div class="label">Outstanding balance</div><div class="amount {{ ($finance['outstanding_balance'] ?? 0) > 0 ? 'warning' : 'positive' }}">KES {{ number_format($finance['outstanding_balance'] ?? 0, 2) }}</div></td>
    </tr></table>
    @if(!empty($finance['note']))<div class="note">{{ $finance['note'] }}</div>@endif
</div>

<div class="section">
    <div class="section-title">Closure controls · {{ $verified }}/{{ count($checks) }} verified</div>
    <table class="data"><thead><tr><th style="width:68%">Required control</th><th>Status</th></tr></thead><tbody>
    @foreach($checks as $label => $done)
        <tr><td>{{ $label }}</td><td class="{{ $done ? 'check' : 'missing' }}">{{ $done ? 'VERIFIED' : 'MISSING' }}</td></tr>
    @endforeach
    </tbody></table>
</div>

<div class="section">
    <div class="section-title">Evidence index · {{ count($docs) }} source files</div>
    <table class="data"><thead><tr><th>Document</th><th style="width:22%">Category</th><th style="width:18%">Source status</th></tr></thead><tbody>
    @forelse($docs as $document)
        <tr><td>{{ $document['name'] ?? 'Document' }}</td><td>{{ $document['category'] ?? 'Other' }}</td><td>{{ strtoupper(str_replace('_', ' ', $document['task_status'] ?? 'available')) }}</td></tr>
    @empty
        <tr><td colspan="3" class="missing">No system evidence was available when this report was generated.</td></tr>
    @endforelse
    @foreach(($report->attachments ?? []) as $attachment)
        <tr><td>{{ $attachment['name'] ?? 'Uploaded attachment' }}</td><td>{{ $attachment['category'] ?? 'Manual upload' }}</td><td>UPLOADED</td></tr>
    @endforeach
    </tbody></table>
</div>

<table class="section"><tr>
    <td style="width:49%;padding-right:1%"><div class="section-title">Outstanding obligations</div><div class="text-box">{{ $report->outstanding_items ?: 'None recorded.' }}</div></td>
    <td style="width:49%;padding-left:1%"><div class="section-title">Lessons and follow-up</div><div class="text-box">{{ $report->recommendations_action_points ?: 'None recorded.' }}</div></td>
</tr></table>

<div class="section">
    <div class="section-title">Authenticated approvals</div>
    <table class="approval"><tr>
        <td><div class="label">Submitted by</div><div class="approval-name">{{ $report->project_officer_signature ?: 'Pending' }}</div><div class="muted">{{ $report->submitted_at?->format('d M Y, H:i') ?: ($report->project_officer_sign_date?->format('d M Y') ?: 'Not submitted') }}</div></td>
        <td><div class="label">Approved by</div><div class="approval-name">{{ $report->reviewed_by ?: 'Pending' }}</div><div class="muted">{{ $report->approved_at?->format('d M Y, H:i') ?: ($report->reviewer_sign_date?->format('d M Y') ?: 'Not approved') }}</div></td>
    </tr></table>
</div>

<div class="section record">
    <strong>Record purpose:</strong> This document certifies operational closure and indexes the supporting records retained in the ERP. Any outstanding client balance remains controlled by Finance until settled.
</div>
</body>
</html>
