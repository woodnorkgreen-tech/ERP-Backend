<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Notifications\InterviewScheduledNotification;
use App\Modules\HR\Notifications\ShortlistNotification;
use App\Models\User;

class InterviewController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /hr/recruitment/admin/interviews
    // ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = Interview::with(['candidate', 'jobPosting', 'createdBy'])
            ->orderBy('scheduled_at', 'asc');

        if ($request->filled('job_posting_id')) {
            $query->where('job_posting_id', $request->job_posting_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('scheduled_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('scheduled_at', '<=', $request->date_to);
        }

        return response()->json($query->get());
    }

    // ─────────────────────────────────────────────
    // GET /hr/recruitment/admin/candidates/{id}/interviews
    // ─────────────────────────────────────────────
    public function candidateInterviews($candidateId)
    {
        $candidate = Candidate::findOrFail($candidateId);
        $interviews = Interview::with(['jobPosting', 'createdBy'])
            ->where('candidate_id', $candidateId)
            ->orderBy('scheduled_at', 'desc')
            ->get();

        return response()->json($interviews);
    }

    // ─────────────────────────────────────────────
    // POST /hr/recruitment/admin/candidates/{id}/interviews
    // ─────────────────────────────────────────────
    public function store(Request $request, $candidateId)
    {
        $candidate = Candidate::findOrFail($candidateId);

        $validated = $request->validate([
            'interview_type'    => 'required|string|in:Phone Screen,Video Call,In-Person,Technical,Panel',
            'scheduled_at'      => 'required|date|after:now',
            'duration_minutes'  => 'nullable|integer|min:15|max:480',
            'location'          => 'nullable|string|max:255',
            'meeting_link'      => 'nullable|url|max:500',
            'interviewer_ids'   => 'nullable|array',
            'interviewer_ids.*' => 'exists:users,id',
            'notes'             => 'nullable|string',
        ]);

        $validated['candidate_id']   = $candidate->id;
        $validated['job_posting_id'] = $candidate->job_posting_id;
        $validated['status']         = 'Scheduled';
        $validated['created_by']     = $request->user()->id;

        $interview = Interview::create($validated);
        $interview->load(['candidate', 'jobPosting', 'createdBy']);

        return response()->json($interview, 201);
    }

    // ─────────────────────────────────────────────
    // POST /hr/recruitment/admin/interviews/bulk
    // ─────────────────────────────────────────────
    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'candidate_ids'     => 'required|array|min:1',
            'candidate_ids.*'   => 'exists:hr_candidates,id',
            'interview_type'    => 'required|string|in:Phone Screen,Video Call,In-Person,Technical,Panel',
            'scheduled_at'      => 'required|date|after:now',
            'duration_minutes'  => 'nullable|integer|min:15|max:480',
            'location'          => 'nullable|string|max:255',
            'meeting_link'      => 'nullable|url|max:500',
            'interviewer_ids'   => 'nullable|array',
            'interviewer_ids.*' => 'exists:users,id',
            'notes'             => 'nullable|string',
        ]);

        $created = DB::transaction(function () use ($validated, $request) {
            $interviews = [];
            foreach ($validated['candidate_ids'] as $candidateId) {
                $candidate = Candidate::find($candidateId);
                if (!$candidate) continue;

                $interviews[] = Interview::create([
                    'candidate_id'    => $candidateId,
                    'job_posting_id'  => $candidate->job_posting_id,
                    'interview_type'  => $validated['interview_type'],
                    'scheduled_at'    => $validated['scheduled_at'],
                    'duration_minutes'=> $validated['duration_minutes'] ?? 60,
                    'location'        => $validated['location'] ?? null,
                    'meeting_link'    => $validated['meeting_link'] ?? null,
                    'interviewer_ids' => $validated['interviewer_ids'] ?? null,
                    'notes'           => $validated['notes'] ?? null,
                    'status'          => 'Scheduled',
                    'created_by'      => $request->user()->id,
                ]);
            }
            return $interviews;
        });

        return response()->json([
            'message' => count($created) . ' interviews scheduled successfully.',
            'count'   => count($created),
        ], 201);
    }

    // ─────────────────────────────────────────────
    // PUT /hr/recruitment/admin/interviews/{id}
    // ─────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $interview = Interview::findOrFail($id);

        $validated = $request->validate([
            'interview_type'    => 'sometimes|string|in:Phone Screen,Video Call,In-Person,Technical,Panel',
            'scheduled_at'      => 'sometimes|date',
            'duration_minutes'  => 'nullable|integer|min:15|max:480',
            'location'          => 'nullable|string|max:255',
            'meeting_link'      => 'nullable|url|max:500',
            'interviewer_ids'   => 'nullable|array',
            'interviewer_ids.*' => 'exists:users,id',
            'notes'             => 'nullable|string',
            'status'            => 'sometimes|string|in:Scheduled,Completed,Cancelled,No-Show',
        ]);

        $interview->update($validated);
        $interview->load(['candidate', 'jobPosting', 'createdBy']);

        return response()->json($interview);
    }

    // ─────────────────────────────────────────────
    // PATCH /hr/recruitment/admin/interviews/{id}/complete
    // ─────────────────────────────────────────────
    public function complete(Request $request, $id)
    {
        $interview = Interview::with('candidate')->findOrFail($id);

        $validated = $request->validate([
            'feedback' => 'nullable|string',
            'outcome'  => 'required|string|in:Pass,Fail,Hold',
        ]);

        DB::transaction(function () use ($interview, $validated) {
            $interview->update([
                'status'   => 'Completed',
                'feedback' => $validated['feedback'] ?? null,
                'outcome'  => $validated['outcome'],
            ]);

            // Auto-update candidate status based on outcome
            $candidate = $interview->candidate;
            if ($validated['outcome'] === 'Pass') {
                $candidate->update([
                    'status' => 'Background Check',
                    'background_check_status' => 'Pending',
                    'background_check_completed_at' => null,
                ]);
            } elseif ($validated['outcome'] === 'Fail') {
                $candidate->update(['status' => 'Rejected']);
            }
            // Hold → candidate stays in Interviewing
        });

        $interview->load(['candidate', 'jobPosting', 'createdBy']);
        return response()->json($interview);
    }

    // ─────────────────────────────────────────────
    // DELETE /hr/recruitment/admin/interviews/{id}
    // ─────────────────────────────────────────────
    public function destroy($id)
    {
        $interview = Interview::findOrFail($id);
        $interview->update(['status' => 'Cancelled']);
        $interview->delete();

        return response()->json(['message' => 'Interview cancelled.']);
    }

    // ─────────────────────────────────────────────
    // POST /hr/recruitment/admin/interviews/{id}/notify
    // Send interview details to the candidate via email
    // ─────────────────────────────────────────────
    public function notifyCandidate($id)
    {
        $interview = Interview::with(['candidate', 'jobPosting'])->findOrFail($id);
        $candidate = $interview->candidate;

        // Use a notifiable object wrapping the candidate email
        Notification::route('mail', $candidate->email)
            ->notify(new InterviewScheduledNotification($interview));

        return response()->json(['message' => 'Notification sent to ' . $candidate->email]);
    }

    // ─────────────────────────────────────────────
    // POST /hr/recruitment/admin/jobs/{id}/notify-shortlisted
    // One-click email blast to all shortlisted candidates
    // ─────────────────────────────────────────────
    public function notifyShortlisted(Request $request, $jobId)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $job = JobPosting::findOrFail($jobId);

        $shortlisted = Candidate::where('job_posting_id', $jobId)
            ->where('status', 'Shortlisted')
            ->get();

        if ($shortlisted->isEmpty()) {
            return response()->json(['message' => 'No shortlisted candidates found for this job.'], 422);
        }

        foreach ($shortlisted as $candidate) {
            Notification::route('mail', $candidate->email)
                ->notify(new ShortlistNotification(
                    $job->title,
                    $validated['subject'],
                    $validated['message']
                ));
        }

        return response()->json([
            'message' => "Notification sent to {$shortlisted->count()} shortlisted candidate(s).",
            'count'   => $shortlisted->count(),
        ]);
    }
}
