<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Lean projects dashboard.
 *
 * One screen, one payload: a handful of KPIs that actually drive a decision,
 * plus a single ranked feed of project-level signals that need a human now.
 *
 * Everything is scoped to a time period. The default is the current month;
 * the caller can ask for a specific month or for all-time (a null range).
 */
class ProjectsDashboardService
{
    /** Projects still in motion — the universe every project-level signal draws from. */
    private const ACTIVE_PROJECT_STATUSES = ['planning', 'in_progress'];

    /** Tasks not yet finished. */
    private const OPEN_TASK_STATUSES = ['pending', 'in_progress'];

    /** For all-time view: a project due within this many days is "approaching". */
    private const DEADLINE_SOON_DAYS = 7;

    /** No update in this many days = the project has stalled. */
    private const STALLED_PROJECT_DAYS = 7;

    /**
     * The entire dashboard in one shot, scoped to a period.
     *
     * @param  array{key:string,label:string,start:?Carbon,end:?Carbon}  $period
     */
    public function getDashboard(array $period): array
    {
        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;

        return [
            'period' => [
                'key' => $period['key'],
                'label' => $period['label'],
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
            'kpis' => $this->kpis($start, $end),
            'signals' => $this->signals($start, $end),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Constrain a query to [start, end] on the given column when a range is set.
     * A null range means "all time" — no constraint.
     */
    private function withinRange($query, string $column, ?Carbon $start, ?Carbon $end)
    {
        if ($start && $end) {
            $query->whereBetween($column, [$start, $end]);
        }

        return $query;
    }

    /**
     * KPIs for the period. Each metric is anchored on its natural timestamp:
     * projects by created/completed date, enquiries by created date, revenue
     * by approval date.
     */
    private function kpis(?Carbon $start, ?Carbon $end): array
    {
        $totalEnquiries = $this->withinRange(ProjectEnquiry::query(), 'created_at', $start, $end)->count();
        $convertedEnquiries = $this->withinRange(ProjectEnquiry::whereNotNull('job_number'), 'created_at', $start, $end)->count();

        $overdueProjects = Project::whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today());
        $this->withinRange($overdueProjects, 'end_date', $start, $end);

        return [
            'active_projects' => $this->withinRange(
                Project::whereIn('status', self::ACTIVE_PROJECT_STATUSES), 'created_at', $start, $end
            )->count(),
            'overdue_projects' => $overdueProjects->count(),
            'conversion_rate' => $totalEnquiries > 0
                ? round(($convertedEnquiries / $totalEnquiries) * 100, 1)
                : 0.0,
            'approved_revenue' => (float) $this->withinRange(
                DB::table('task_quote_data')->where('status', 'approved'), 'approval_date', $start, $end
            )->sum('quote_amount'),
            'completed_projects' => $this->withinRange(
                Project::where('status', 'completed'), 'updated_at', $start, $end
            )->count(),
            // Denominators / context.
            'total_enquiries' => $totalEnquiries,
            'total_projects' => $this->withinRange(Project::query(), 'created_at', $start, $end)->count(),
        ];
    }

    /**
     * One ranked feed of project-level health signals — timeline slippage and
     * stalls across whole projects, scoped to the period. Highest severity first.
     */
    private function signals(?Carbon $start, ?Carbon $end): array
    {
        $signals = collect()
            ->merge($this->overdueProjectSignals($start, $end))
            ->merge($this->deadlineApproachingProjectSignals($start, $end))
            ->merge($this->stalledProjectSignals($start, $end))
            ->merge($this->missingTimelineProjectSignals($start, $end));

        $severityRank = ['high' => 3, 'medium' => 2, 'low' => 1];

        // Single numeric key: severity dominates, urgency (larger = worse) breaks ties.
        return $signals
            ->sortByDesc(fn ($signal) => (($severityRank[$signal['severity']] ?? 0) * 1_000_000_000) + $signal['_age'])
            ->map(fn ($signal) => collect($signal)->except('_age')->all())
            ->values()
            ->all();
    }

    /** Active projects whose deadline (within the period) has already passed. */
    private function overdueProjectSignals(?Carbon $start, ?Carbon $end)
    {
        $query = Project::with('enquiry.client')
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today());
        $this->withinRange($query, 'end_date', $start, $end);

        return $query->get()->map(fn ($project) => [
            'id' => "overdue_project_{$project->id}",
            'type' => 'overdue_project',
            'severity' => 'high',
            'title' => 'Project past deadline',
            'message' => $this->projectLabel($project) . " was due {$project->end_date->diffForHumans()} and is still open",
            'action_url' => $this->projectUrl($project),
            'age_label' => $project->end_date->diffForHumans(),
            '_age' => abs(Carbon::now()->diffInSeconds($project->end_date)),
        ]);
    }

    /** Active projects whose deadline falls in the upcoming part of the period. */
    private function deadlineApproachingProjectSignals(?Carbon $start, ?Carbon $end)
    {
        // Lower bound is never in the past; upper bound is the period end, or a
        // rolling 7-day window when looking at all-time.
        $from = Carbon::today();
        if ($start && $start->gt($from)) {
            $from = $start->copy();
        }
        $to = $end ?? Carbon::today()->addDays(self::DEADLINE_SOON_DAYS);

        if ($from->gt($to)) {
            return collect();
        }

        return Project::with('enquiry.client')
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $from)
            ->whereDate('end_date', '<=', $to)
            ->get()
            ->map(function ($project) {
                $secondsUntil = abs(Carbon::now()->diffInSeconds($project->end_date));
                return [
                    'id' => "deadline_soon_project_{$project->id}",
                    'type' => 'deadline_approaching',
                    'severity' => 'medium',
                    'title' => 'Deadline approaching',
                    'message' => $this->projectLabel($project) . " is due {$project->end_date->diffForHumans()}",
                    'action_url' => $this->projectUrl($project),
                    'age_label' => $project->end_date->diffForHumans(),
                    // Sooner deadline = larger key, so it floats to the top of its tier.
                    '_age' => (self::DEADLINE_SOON_DAYS * 86400) - $secondsUntil,
                ];
            });
    }

    /** Active projects (due in the period) with no movement for STALLED_PROJECT_DAYS. */
    private function stalledProjectSignals(?Carbon $start, ?Carbon $end)
    {
        $query = Project::with('enquiry.client')
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->where('updated_at', '<', Carbon::now()->subDays(self::STALLED_PROJECT_DAYS));
        $this->withinRange($query, 'end_date', $start, $end);

        return $query->get()->map(fn ($project) => [
            'id' => "stalled_project_{$project->id}",
            'type' => 'stalled_project',
            'severity' => 'medium',
            'title' => 'Project stalled',
            'message' => $this->projectLabel($project) . " hasn't moved in {$project->updated_at->diffForHumans(null, true)}",
            'action_url' => $this->projectUrl($project),
            'age_label' => $project->updated_at->diffForHumans(),
            '_age' => abs(Carbon::now()->diffInSeconds($project->updated_at)),
        ]);
    }

    /**
     * Active projects you can't track because a start or end date is missing.
     * These have no deadline to place in a month, so they only surface when the
     * period includes today (current month / all-time) — a present-tense concern.
     */
    private function missingTimelineProjectSignals(?Carbon $start, ?Carbon $end)
    {
        $includesToday = !$start || Carbon::today()->between($start, $end);
        if (!$includesToday) {
            return collect();
        }

        return Project::with('enquiry.client')
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereNull('end_date'))
            ->get()
            ->map(function ($project) {
                $missing = match (true) {
                    !$project->start_date && !$project->end_date => 'no start or end date',
                    !$project->end_date => 'no end date',
                    default => 'no start date',
                };
                return [
                    'id' => "no_timeline_project_{$project->id}",
                    'type' => 'missing_timeline',
                    'severity' => 'low',
                    'title' => 'Project has no timeline',
                    'message' => $this->projectLabel($project) . " is active but has {$missing} — delivery can't be tracked",
                    'action_url' => $this->projectUrl($project),
                    'age_label' => $project->created_at?->diffForHumans() ?? '',
                    '_age' => $project->created_at ? abs(Carbon::now()->diffInSeconds($project->created_at)) : 0,
                ];
            });
    }

    /** Human-readable "JOB-123 (Acme Ltd)" style label for a project. */
    private function projectLabel(Project $project): string
    {
        $client = $project->enquiry->client->full_name ?? 'Unknown client';
        $ref = $project->project_id
            ?: ($project->enquiry->enquiry_number ?? "Project #{$project->id}");

        return "{$ref} ({$client})";
    }

    /** Projects surface through the enquiry that backs them. */
    private function projectUrl(Project $project): string
    {
        return "/projects/enquiries/{$project->enquiry_id}";
    }
}
