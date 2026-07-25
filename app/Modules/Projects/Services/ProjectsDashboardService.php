<?php

namespace App\Modules\Projects\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use Carbon\Carbon;

/**
 * Lean projects dashboard.
 *
 * One screen, one payload: a handful of KPIs that actually drive a decision,
 * plus a single ranked feed of project-level signals that need a human now.
 *
 * Portfolio health is always live. The selected period only scopes throughput
 * metrics such as enquiries, conversion, and completed projects.
 */
class ProjectsDashboardService
{
    /** Projects still in motion — the universe every project-level signal draws from. */
    private const ACTIVE_PROJECT_STATUSES = ['planning', 'in_progress'];

    /** For all-time view: a project due within this many days is "approaching". */
    private const DEADLINE_SOON_DAYS = 7;

    /** No update in this many days = the project has stalled. */
    private const STALLED_PROJECT_DAYS = 7;

    /** Keep the dashboard actionable; full counts still cover every signal. */
    private const SIGNAL_LIMIT_PER_TYPE = 15;

    /**
     * The entire dashboard in one shot, scoped to a period.
     *
     * @param  array{key:string,label:string,start:?Carbon,end:?Carbon}  $period
     */
    public function getDashboard(array $period): array
    {
        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;
        $signals = $this->signals();
        $rankById = array_flip(array_column($signals, 'id'));
        $prioritySignals = collect($signals)
            ->groupBy('type')
            ->flatMap(fn ($group) => $group->take(self::SIGNAL_LIMIT_PER_TYPE))
            ->sortBy(fn ($signal) => $rankById[$signal['id']] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return [
            'period' => [
                'key' => $period['key'],
                'label' => $period['label'],
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
            'kpis' => $this->kpis($start, $end),
            'signals' => $prioritySignals,
            'signal_counts' => collect($signals)->countBy('type')->all(),
            'total_signals' => count($signals),
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
     * Live portfolio counts plus period throughput metrics. Completed projects
     * use updated_at because the projects table has no completed_at column.
     */
    private function kpis(?Carbon $start, ?Carbon $end): array
    {
        $totalEnquiries = $this->withinRange(ProjectEnquiry::query(), 'created_at', $start, $end)->count();
        $convertedEnquiries = $this->withinRange(ProjectEnquiry::whereNotNull('job_number'), 'created_at', $start, $end)->count();

        $overdueProjects = Project::whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today());

        return [
            // Snapshot metrics must never hide older work that is still active.
            'active_projects' => Project::whereIn('status', self::ACTIVE_PROJECT_STATUSES)->count(),
            'overdue_projects' => $overdueProjects->count(),
            'conversion_rate' => $totalEnquiries > 0
                ? round(($convertedEnquiries / $totalEnquiries) * 100, 1)
                : 0.0,
            'completed_projects' => $this->withinRange(
                Project::where('status', 'completed'), 'updated_at', $start, $end
            )->count(),
            // Denominators / context.
            'total_enquiries' => $totalEnquiries,
            'total_projects' => $this->withinRange(Project::query(), 'created_at', $start, $end)->count(),
        ];
    }

    /**
     * One ranked feed of current project health signals. Highest severity first.
     */
    private function signals(): array
    {
        $signals = collect()
            ->merge($this->overdueProjectSignals())
            ->merge($this->deadlineApproachingProjectSignals())
            ->merge($this->stalledProjectSignals())
            ->merge($this->missingTimelineProjectSignals());

        $severityRank = ['high' => 3, 'medium' => 2, 'low' => 1];

        // Single numeric key: severity dominates, urgency (larger = worse) breaks ties.
        return $signals
            ->sortByDesc(fn ($signal) => (($severityRank[$signal['severity']] ?? 0) * 1_000_000_000) + $signal['_age'])
            ->map(fn ($signal) => collect($signal)->except('_age')->all())
            ->values()
            ->all();
    }

    /** Active projects whose deadline has already passed. */
    private function overdueProjectSignals()
    {
        $query = Project::with(['enquiry.client', 'enquiry.projectOfficer'])
            ->withCount([
                'projectTasks as tasks_total_count',
                'projectTasks as tasks_completed_count' => fn ($q) => $q->completed(),
            ])
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today());
        return $query->get()->map(fn ($project) => [
            'id' => "overdue_project_{$project->id}",
            'type' => 'overdue_project',
            'severity' => 'high',
            'title' => 'Project past deadline',
            'message' => $this->projectLabel($project) . " was due {$project->end_date->diffForHumans()} and is still open",
            'action_url' => $this->projectUrl($project),
            'age_label' => $project->end_date->diffForHumans(),
            'owner' => $this->projectOwner($project),
            'metrics' => [
                ...$this->taskProgress($project),
                'days_overdue' => (int) abs(Carbon::today()->diffInDays($project->end_date)),
                'deadline_date' => $project->end_date->toDateString(),
            ],
            '_age' => abs(Carbon::now()->diffInSeconds($project->end_date)),
        ]);
    }

    /** Active projects due in the next seven days. */
    private function deadlineApproachingProjectSignals()
    {
        $from = Carbon::today();
        $to = Carbon::today()->addDays(self::DEADLINE_SOON_DAYS);

        return Project::with(['enquiry.client', 'enquiry.projectOfficer'])
            ->withCount([
                'projectTasks as tasks_total_count',
                'projectTasks as tasks_completed_count' => fn ($q) => $q->completed(),
            ])
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
                    'owner' => $this->projectOwner($project),
                    'metrics' => [
                        ...$this->taskProgress($project),
                        'days_remaining' => (int) abs(Carbon::today()->diffInDays($project->end_date)),
                        'deadline_date' => $project->end_date->toDateString(),
                    ],
                    // Sooner deadline = larger key, so it floats to the top of its tier.
                    '_age' => (self::DEADLINE_SOON_DAYS * 86400) - $secondsUntil,
                ];
            });
    }

    /** Active projects with no project or task activity for seven days. */
    private function stalledProjectSignals()
    {
        $query = Project::with(['enquiry.client', 'enquiry.projectOfficer'])
            ->withMax('projectTasks as last_task_activity_at', 'updated_at')
            ->withCount([
                'projectTasks as tasks_total_count',
                'projectTasks as tasks_completed_count' => fn ($q) => $q->completed(),
            ])
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES);

        return $query->get()
            ->map(function ($project) {
                $taskActivity = $project->last_task_activity_at
                    ? Carbon::parse($project->last_task_activity_at)
                    : null;
                $lastActivity = $taskActivity && $taskActivity->gt($project->updated_at)
                    ? $taskActivity
                    : $project->updated_at;

                return [$project, $lastActivity];
            })
            ->filter(fn ($entry) => $entry[1]->lt(Carbon::now()->subDays(self::STALLED_PROJECT_DAYS)))
            ->map(fn ($entry) => [
                'id' => "stalled_project_{$entry[0]->id}",
                'type' => 'stalled_project',
                'severity' => 'medium',
                'title' => 'Project stalled',
                'message' => $this->projectLabel($entry[0]) . " hasn't moved in {$entry[1]->diffForHumans(null, true)}",
                'action_url' => $this->projectUrl($entry[0]),
                'age_label' => $entry[1]->diffForHumans(),
                'owner' => $this->projectOwner($entry[0]),
                'metrics' => [
                    ...$this->taskProgress($entry[0]),
                    'days_idle' => (int) abs(Carbon::now()->diffInDays($entry[1])),
                    'last_activity_date' => $entry[1]->toDateString(),
                ],
                '_age' => abs(Carbon::now()->diffInSeconds($entry[1])),
            ]);
    }

    /**
     * Active projects you can't track because a start or end date is missing.
     * These always surface because they prevent live portfolio tracking.
     */
    private function missingTimelineProjectSignals()
    {
        return Project::with(['enquiry.client', 'enquiry.projectOfficer'])
            ->withCount([
                'projectTasks as tasks_total_count',
                'projectTasks as tasks_completed_count' => fn ($q) => $q->completed(),
            ])
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
                    'owner' => $this->projectOwner($project),
                    'metrics' => [
                        ...$this->taskProgress($project),
                        'days_active' => $project->created_at ? (int) abs(Carbon::now()->diffInDays($project->created_at)) : 0,
                        'missing_start' => !$project->start_date,
                        'missing_end' => !$project->end_date,
                    ],
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

    /**
     * Projects surface through the enquiry that backs them. There's no
     * /projects/enquiries/{id} route — the enquiries list opens a specific
     * record's detail view via an `id` query param instead.
     */
    private function projectUrl(Project $project): string
    {
        return "/projects/enquiries?id={$project->enquiry_id}";
    }

    /** Who to chase for this signal — the enquiry's assigned project officer, if any. */
    private function projectOwner(Project $project): ?string
    {
        return $project->enquiry?->projectOfficer?->name;
    }

    /** Task completion, from the withCount() aliases each signal query already loads. */
    private function taskProgress(Project $project): array
    {
        return [
            'tasks_completed' => (int) ($project->tasks_completed_count ?? 0),
            'tasks_total' => (int) ($project->tasks_total_count ?? 0),
        ];
    }
}
