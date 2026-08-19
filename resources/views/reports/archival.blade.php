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
        .header { border-bottom: 2px solid #07ADD4; padding-bottom: 12px; margin-bottom: 14px; }
        .brand-logo { display: block; width: 132px; height: auto; }
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
        .scope { border-left: 3px solid #07ADD4; background: #f7fafc; padding: 8px 10px; }
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
        .decision { margin: 10px 0 14px; border: 1px solid #cbd5e0; border-left: 4px solid #07ADD4; background: #f8fafc; }
        .decision td { width: 25%; padding: 8px 10px; border-right: 1px solid #dbe2ea; }
        .decision td:last-child { border-right: 0; }
        .decision .primary { color: #087f5b; }
        .appendix { page-break-before: always; }
        .appendix-intro { margin-bottom: 10px; color: #718096; font-size: 8px; }
        .feedback-summary td { width: 25%; border: 1px solid #dbe2ea; padding: 7px 9px; }
        .feedback-comment { color: #4a5568; font-size: 8px; margin-top: 2px; white-space: pre-wrap; }
        .feedback-section { page-break-inside: avoid; margin-top: 10px; }
    </style>
</head>
<body>
@php
    $context = $closureContext ?? [];
    $workflowTasks = $context['tasks'] ?? [];
    $summary = $context['task_summary'] ?? ['total' => 0, 'completed' => 0, 'open' => 0, 'completion_percentage' => 100];
    $checks = collect($context['required_checks'] ?? [])->mapWithKeys(
        fn (array $check) => [$check['label'] => (bool) $check['auto_verified'] && (bool) $report->{$check['key']}]
    )->all();
    $verified = collect($checks)->filter()->count();
    $finance = $financialSummary ?? [];
    $docs = $systemDocuments ?? [];
    $manualAttachments = $report->attachments ?? [];
    $evidenceTotal = count($docs) + count($manualAttachments);
    $status = $report->status ?: 'draft';
    $hasFinanceStage = collect($workflowTasks)->whereIn('type', ['quote', 'quote_approval', 'budget'])->isNotEmpty();
    $hasHandoverStage = collect($workflowTasks)->contains('type', 'handover');
    $handover = $handoverSummary ?? null;
@endphp

<div class="footer">
    <table><tr><td>Woodnork Green · Project closure record · {{ $report->archive_reference ?: 'Reference pending' }}</td><td style="text-align:right">Generated {{ now()->format('d M Y H:i') }} · Page <span class="page"></span></td></tr></table>
</div>

<table class="header"><tr>
    <td style="width:45%"><img class="brand-logo" src="{{ public_path(config('brand.logo')) }}" alt="Woodnork Green logo"><div class="muted small">Project delivery and archival control</div></td>
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

<table class="decision"><tr>
    <td><div class="label">Closure decision</div><div class="value primary">{{ $status === 'approved' ? 'APPROVED' : strtoupper($status) }}</div></td>
    <td><div class="label">Project workflow</div><div class="value">{{ $context['preset_label'] ?? 'Custom workflow' }}</div></td>
    <td><div class="label">Stages completed</div><div class="value">{{ $summary['completed'] }}/{{ $summary['total'] }} · {{ $summary['completion_percentage'] }}%</div></td>
    <td><div class="label">Archived evidence</div><div class="value">{{ $evidenceTotal }} file{{ $evidenceTotal === 1 ? '' : 's' }}</div></td>
</tr></table>

<div class="section">
    <div class="section-title">Delivered scope</div>
    <div class="scope">{!! nl2br(e($report->project_scope ?: 'No project scope was recorded.')) !!}</div>
</div>

@if($hasFinanceStage)
<div class="section">
    <div class="section-title">Finance reconciliation</div>
    <table class="metrics"><tr>
        <td><div class="label">Approved quote</div><div class="amount">KES {{ number_format($finance['approved_quote'] ?? 0, 2) }}</div></td>
        <td><div class="label">Payments received</div><div class="amount positive">KES {{ number_format($finance['payments_received'] ?? 0, 2) }}</div></td>
        <td><div class="label">Outstanding balance</div><div class="amount {{ ($finance['outstanding_balance'] ?? 0) > 0 ? 'warning' : 'positive' }}">KES {{ number_format($finance['outstanding_balance'] ?? 0, 2) }}</div></td>
    </tr></table>
    @if(!empty($finance['note']))<div class="note">{{ $finance['note'] }}</div>@endif
</div>
@endif

@if($handover)
<div class="section">
    <div class="section-title">Client handover outcome</div>
    <table class="feedback-summary"><tr>
        <td><div class="label">Overall rating</div><div class="value">{{ $handover['average_rating'] !== null ? number_format($handover['average_rating'], 1).' / 5' : 'Not recorded' }}</div></td>
        <td><div class="label">Delivered on time</div><div class="value {{ $handover['delivered_on_time'] === true ? 'positive' : ($handover['delivered_on_time'] === false ? 'warning' : '') }}">{{ $handover['delivered_on_time'] === null ? 'NOT RECORDED' : ($handover['delivered_on_time'] ? 'YES' : 'NO') }}</div></td>
        <td><div class="label">Respondent</div><div class="value">{{ $handover['respondent'] ?? 'Client representative' }}</div></td>
        <td><div class="label">CS review</div><div class="value">{{ strtoupper(str_replace('_', ' ', $handover['review_status'] ?? 'pending')) }}</div></td>
    </tr></table>
    @if(!empty($handover['ncr']))<div class="note"><strong>Linked corrective action:</strong> {{ $handover['ncr']['reference'] ?? 'Client issue' }} · {{ strtoupper($handover['ncr']['status'] ?? 'open') }}</div>@endif
</div>
@elseif($hasHandoverStage)
<div class="note"><strong>Handover evidence missing:</strong> No submitted client survey responses were found for this project when the report was generated.</div>
@endif

<div class="section">
    <div class="section-title">Closure controls · {{ $verified }}/{{ count($checks) }} verified</div>
    <table class="data"><thead><tr><th style="width:68%">Required control</th><th>Status</th></tr></thead><tbody>
    @foreach($checks as $label => $done)
        <tr><td>{{ $label }}</td><td class="{{ $done ? 'check' : 'missing' }}">{{ $done ? 'VERIFIED' : 'MISSING' }}</td></tr>
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

@if($report->correction_requested_at)
<div class="note"><strong>Revision {{ $report->revision_number ?: 1 }} requested by {{ $report->correctionRequester?->name ?: 'Management reviewer' }} on {{ $report->correction_requested_at->format('d M Y, H:i') }}:</strong> {{ $report->correction_notes }} @if($report->correction_resolved_at)<span class="positive"> · Corrected and resubmitted {{ $report->correction_resolved_at->format('d M Y, H:i') }}</span>@endif</div>
@endif

<div class="section record">
    <strong>Record purpose:</strong> This document certifies operational closure and indexes the supporting records retained in the ERP. Any outstanding client balance remains controlled by Finance until settled.
</div>

<div class="appendix">
    <div class="section-title">Audit appendix · Workflow and evidence trail</div>
    <div class="appendix-intro">This appendix records the project stages and documents evaluated at the time of closure. It supports audit review without obscuring the closure decision on page one.</div>

    <div class="section">
        <div class="section-title">Workflow execution · {{ $context['preset_label'] ?? 'Custom project workflow' }}</div>
        <table class="data"><thead><tr><th>Project stage</th><th style="width:25%">Phase</th><th style="width:16%">Status</th><th style="width:18%">Completed</th></tr></thead><tbody>
        @forelse($workflowTasks as $workflowTask)
            <tr><td>{{ $workflowTask['title'] }}</td><td>{{ $workflowTask['phase'] }}</td><td class="{{ $workflowTask['status'] === 'completed' ? 'check' : 'missing' }}">{{ strtoupper(str_replace('_', ' ', $workflowTask['status'])) }}</td><td>{{ !empty($workflowTask['completed_at']) ? \Carbon\Carbon::parse($workflowTask['completed_at'])->format('d M Y') : '—' }}</td></tr>
        @empty
            <tr><td colspan="4">No workflow stages were available.</td></tr>
        @endforelse
        </tbody></table>
    </div>

    @if($handover)
    <div class="section">
        <div class="section-title">Client handover responses</div>
        <table class="identity"><tr>
            <td><div class="label">Submitted</div><div class="value">{{ !empty($handover['submitted_at']) ? \Carbon\Carbon::parse($handover['submitted_at'])->format('d M Y, H:i') : '—' }}</div></td>
            <td><div class="label">Feedback source</div><div class="value">{{ $handover['feedback_source'] ?? 'Survey link' }}</div></td>
            <td><div class="label">Respondent</div><div class="value">{{ $handover['respondent'] ?? 'Client representative' }}</div></td>
            <td><div class="label">Reviewed by</div><div class="value">{{ $handover['reviewed_by'] ?? 'Pending CS review' }}</div></td>
        </tr></table>

        @foreach(($handover['sections'] ?? []) as $feedbackSection)
        <div class="feedback-section">
            <div class="label" style="margin-bottom:4px">{{ $feedbackSection['title'] }}</div>
            <table class="data"><thead><tr><th style="width:57%">Question</th><th>Client response</th></tr></thead><tbody>
            @foreach($feedbackSection['answers'] as $answer)
                <tr><td>{{ $answer['label'] }}</td><td><strong>{{ $answer['value'] ?? '—' }}</strong>@if(!empty($answer['remarks']))<div class="feedback-comment">{{ $answer['remarks'] }}</div>@endif</td></tr>
            @endforeach
            </tbody></table>
        </div>
        @endforeach

        @if(!empty($handover['review_notes']) || !empty($handover['evidence_notes']))
        <table style="margin-top:10px"><tr>
            <td style="width:49%;padding-right:1%"><div class="label">Client Service review notes</div><div class="text-box">{{ $handover['review_notes'] ?: 'None recorded.' }}</div></td>
            <td style="width:49%;padding-left:1%"><div class="label">Handover evidence notes</div><div class="text-box">{{ $handover['evidence_notes'] ?: 'None recorded.' }}</div></td>
        </tr></table>
        @endif
    </div>
    @endif

    <div class="section">
        <div class="section-title">Evidence index · {{ $evidenceTotal }} files</div>
        <table class="data"><thead><tr><th>Document</th><th style="width:22%">Category</th><th style="width:18%">Source status</th></tr></thead><tbody>
        @forelse($docs as $document)
            <tr><td>{{ $document['name'] ?? 'Document' }}</td><td>{{ $document['category'] ?? 'Other' }}</td><td>{{ strtoupper(str_replace('_', ' ', $document['task_status'] ?? 'available')) }}</td></tr>
        @empty
            @if(empty($manualAttachments))<tr><td colspan="3" class="missing">No supporting evidence was available when this report was generated.</td></tr>@endif
        @endforelse
        @foreach($manualAttachments as $attachment)
            <tr><td>{{ $attachment['name'] ?? 'Uploaded attachment' }}</td><td>{{ $attachment['category'] ?? 'Manual upload' }}</td><td>UPLOADED</td></tr>
        @endforeach
        </tbody></table>
    </div>
</div>
</body>
</html>
