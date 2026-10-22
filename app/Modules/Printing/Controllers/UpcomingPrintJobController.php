<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Support\DesignSchedule;
use App\Modules\Printing\Resources\UpcomingProjectResource;
use App\Support\ProjectSetupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Upcoming projects for printing planning, read-only.
 *
 * Starts from the same design jobs design sees (design-workflow projects due in the window),
 * so a project appears here even before design has created a task for it.
 */
class UpcomingPrintJobController extends Controller
{
    private const STATUSES = ['pending', 'in_design', 'awaiting_client_approval', 'client_changes_requested', 'done'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'schedule_scope' => ['sometimes', Rule::in(['upcoming', 'overdue', 'all'])],
            'printing_schedule' => ['sometimes', Rule::in(['scheduled', 'unscheduled'])],
        ]);

        $scope = $filters['schedule_scope'] ?? 'upcoming';
        $setupDate = ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id');

        $projects = DesignJob::query()
            ->select('design_jobs.*')
            ->selectSub($setupDate, 'project_setup_date')
            ->where('status', '!=', 'cancelled')
            ->whereHas('enquiry.deliverables', fn ($query) => $query->whereNull('archived_at'))
            // Same date rules as design's job list, so the two screens agree.
            ->tap(fn ($query) => $scope === 'overdue'
                ? DesignSchedule::overdueJobs($query)
                : ($scope === 'upcoming'
                    ? $query->where(fn ($match) => ProjectSetupSchedule::filterDateScope($match, $setupDate, $scope)
                        ->orWhereHas('printingSchedule', fn ($scheduled) => $scheduled
                            ->whereDate('printing_date', '>=', now('Africa/Nairobi')->toDateString())))
                    : $query))
            ->with([
                'enquiry.client',
                'client',
                'enquiry.deliverables' => fn ($query) => $query->whereNull('archived_at')->orderBy('sort_order')->orderBy('id'),
                'items.assignedUser:id,name',
                'printingSchedule.machine:id,name',
                'printingSchedule.operator:id,name',
            ])
            ->when($filters['printing_schedule'] ?? null, fn ($query, $status) => $status === 'scheduled'
                ? $query->whereHas('printingSchedule', fn ($scheduled) => $scheduled->whereNotNull('printing_date'))
                : $query->whereDoesntHave('printingSchedule', fn ($scheduled) => $scheduled->whereNotNull('printing_date')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(trim($filters['search'] ?? ''), function ($query, $search) {
                $term = '%'.$search.'%';
                $query->where(fn ($match) => $match
                    ->where('title', 'like', $term)
                    ->orWhere('job_number', 'like', $term)
                    ->orWhereHas('client', fn ($client) => $client->where('full_name', 'like', $term))
                    ->orWhereHas('enquiry', fn ($enquiry) => $enquiry
                        ->where('title', 'like', $term)
                        ->orWhereHas('client', fn ($client) => $client->where('full_name', 'like', $term))));
            })
            ->orderByRaw('project_setup_date IS NULL')
            ->orderBy('project_setup_date')
            ->orderBy('design_jobs.id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($projects->through(fn ($project) => new UpcomingProjectResource($project)));
    }
}
