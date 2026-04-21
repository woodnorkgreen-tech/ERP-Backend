<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Incident Report #{{ $incident->id }}</title>
    <style>
        @page {
            margin: 1in;
            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 10px;
                color: #666;
            }
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }
        .header p {
            margin: 5px 0;
            font-size: 14px;
        }
        .section {
            margin-bottom: 20px;
        }
        .section h2 {
            font-size: 16px;
            color: #333;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .field {
            margin-bottom: 8px;
        }
        .field-label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        .field-value {
            display: inline-block;
        }
        .grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        .grid-row {
            display: table-row;
        }
        .grid-cell {
            display: table-cell;
            padding: 4px;
            vertical-align: top;
        }
        .grid-cell.label {
            width: 150px;
            font-weight: bold;
        }
        .comments {
            margin-top: 10px;
        }
        .comment {
            border: 1px solid #ddd;
            padding: 8px;
            margin-bottom: 8px;
            background: #f9f9f9;
        }
        .comment-header {
            font-weight: bold;
            margin-bottom: 4px;
        }
        .activity {
            margin-top: 10px;
        }
        .activity-item {
            border-left: 3px solid #333;
            padding-left: 8px;
            margin-bottom: 8px;
        }
        .attachments {
            margin-top: 10px;
        }
        .attachment {
            margin-bottom: 4px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>Incident Management System</h1>
        <p>Incident Report #{{ $incident->id }}</p>
        <p>Generated on {{ date('F j, Y \a\t g:i A') }}</p>
    </div>

    <!-- Incident Details -->
    <div class="section">
        <h2>Incident Details</h2>
        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell label">ID:</div>
                <div class="grid-cell">#{{ $incident->id }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Title:</div>
                <div class="grid-cell">{{ $incident->title }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Location:</div>
                <div class="grid-cell">{{ $incident->location }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Date & Time:</div>
                <div class="grid-cell">{{ \Carbon\Carbon::parse($incident->incident_datetime)->format('M j, Y g:i A') }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Severity:</div>
                <div class="grid-cell">{{ $incident->severity }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Status:</div>
                <div class="grid-cell">{{ $incident->status }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Department:</div>
                <div class="grid-cell">{{ $incident->department ? $incident->department->name : 'N/A' }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Date Reported:</div>
                <div class="grid-cell">{{ \Carbon\Carbon::parse($incident->date_reported)->format('M j, Y g:i A') }}</div>
            </div>
            @if($incident->equipment_involved)
            <div class="grid-row">
                <div class="grid-cell label">Equipment Involved:</div>
                <div class="grid-cell">{{ $incident->equipment_involved }}</div>
            </div>
            @endif
        </div>
    </div>

    <!-- Description -->
    <div class="section">
        <h2>Description</h2>
        <p>{{ $incident->description ?: 'No description provided.' }}</p>
    </div>

    <!-- Categories -->
    @if($incident->incident_types && count($incident->incident_types) > 0)
    <div class="section">
        <h2>Categories & Types</h2>
        <p>{{ implode(', ', $incident->incident_types) }}</p>
    </div>
    @endif

    <!-- Root Cause Analysis -->
    @if($incident->root_cause || $incident->corrective_actions || $incident->preventive_measures)
    <div class="section">
        <h2>Root Cause Analysis</h2>
        @if($incident->root_cause)
        <div class="field">
            <div class="field-label">Root Cause:</div>
            <div class="field-value">{{ $incident->root_cause }}</div>
        </div>
        @endif
        @if($incident->corrective_actions)
        <div class="field">
            <div class="field-label">Corrective Actions:</div>
            <div class="field-value">{{ $incident->corrective_actions }}</div>
        </div>
        @endif
        @if($incident->preventive_measures)
        <div class="field">
            <div class="field-label">Preventive Measures:</div>
            <div class="field-value">{{ $incident->preventive_measures }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Review Details -->
    @if($incident->reviewed_by)
    <div class="section">
        <h2>Review Details</h2>
        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell label">Reviewed By:</div>
                <div class="grid-cell">{{ $incident->reviewer ? $incident->reviewer->name : 'Unknown' }}</div>
            </div>
            <div class="grid-row">
                <div class="grid-cell label">Reviewed At:</div>
                <div class="grid-cell">{{ \Carbon\Carbon::parse($incident->reviewed_at)->format('M j, Y g:i A') }}</div>
            </div>
            @if($incident->responsible_party)
            <div class="grid-row">
                <div class="grid-cell label">Responsible Party:</div>
                <div class="grid-cell">{{ $incident->responsible_party }}</div>
            </div>
            @endif
            @if($incident->impact_analysis)
            <div class="grid-row">
                <div class="grid-cell label">Impact Analysis:</div>
                <div class="grid-cell">{{ $incident->impact_analysis }}</div>
            </div>
            @endif
            @if($incident->short_term_fixes)
            <div class="grid-row">
                <div class="grid-cell label">Short Term Fixes:</div>
                <div class="grid-cell">{{ $incident->short_term_fixes }}</div>
            </div>
            @endif
            @if($incident->long_term_measures)
            <div class="grid-row">
                <div class="grid-cell label">Long Term Measures:</div>
                <div class="grid-cell">{{ $incident->long_term_measures }}</div>
            </div>
            @endif
            @if($incident->review_notes)
            <div class="grid-row">
                <div class="grid-cell label">Review Notes:</div>
                <div class="grid-cell">{{ $incident->review_notes }}</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <!-- Reporter Information -->
    <div class="section">
        <h2>Reporter Information</h2>
        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell label">Name:</div>
                <div class="grid-cell">{{ $incident->reporter_name ?: ($incident->reporter ? $incident->reporter->name : 'Guest') }}</div>
            </div>
            @if($incident->reporter_email)
            <div class="grid-row">
                <div class="grid-cell label">Email:</div>
                <div class="grid-cell">{{ $incident->reporter_email ?: ($incident->reporter ? $incident->reporter->email : 'N/A') }}</div>
            </div>
            @endif
            @if($incident->job_title)
            <div class="grid-row">
                <div class="grid-cell label">Job Title:</div>
                <div class="grid-cell">{{ $incident->job_title }}</div>
            </div>
            @endif
            @if($incident->contact_info)
            <div class="grid-row">
                <div class="grid-cell label">Contact:</div>
                <div class="grid-cell">{{ $incident->contact_info }}</div>
            </div>
            @endif
        </div>
    </div>

    <!-- Additional Information -->
    @if($incident->witnesses || $incident->additional_notes)
    <div class="section">
        <h2>Additional Information</h2>
        @if($incident->witnesses)
        <div class="field">
            <div class="field-label">Witnesses:</div>
            <div class="field-value">{{ $incident->witnesses }}</div>
        </div>
        @endif
        @if($incident->additional_notes)
        <div class="field">
            <div class="field-label">Additional Notes:</div>
            <div class="field-value">{{ $incident->additional_notes }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Comments -->
    @if($incident->comments && count($incident->comments) > 0)
    <div class="section">
        <h2>Comments</h2>
        <div class="comments">
            @foreach($incident->comments as $comment)
            <div class="comment">
                <div class="comment-header">
                    {{ $comment->user ? $comment->user->name : 'Unknown' }} - {{ \Carbon\Carbon::parse($comment->created_at)->format('M j, Y g:i A') }}
                </div>
                <div>{{ $comment->comment }}</div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Activity Log -->
    @if($incident->activity_logs && count($incident->activity_logs) > 0)
    <div class="section">
        <h2>Activity Log</h2>
        <div class="activity">
            @foreach($incident->activity_logs as $log)
            <div class="activity-item">
                <strong>{{ \Carbon\Carbon::parse($log->created_at)->format('M j, Y g:i A') }}:</strong> {{ $log->action }}
                @if($log->details)
                <br><em>{{ $log->details }}</em>
                @endif
                @if($log->user)
                <br><small>by {{ $log->user->name }}</small>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Attachments -->
    @if($incident->evidence_paths && count($incident->evidence_paths) > 0)
    <div class="section">
        <h2>Attachments</h2>
        <div class="attachments">
            @foreach($incident->evidence_paths as $path)
            <div class="attachment">{{ basename($path) }}</div>
            @endforeach
        </div>
    </div>
    @endif
</body>
</html>

