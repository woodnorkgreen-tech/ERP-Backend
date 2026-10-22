<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Design\Models\DesignJob;
use App\Modules\HR\Models\Department;
use App\Modules\Printing\Models\PrintProjectSchedule;
use App\Modules\Printing\Resources\UpcomingProjectResource;
use App\Modules\Printing\Support\PrintingAccess;
use App\Support\ProjectSetupSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrintProjectScheduleController extends Controller
{
    public function update(Request $request, DesignJob $designJob): JsonResponse
    {
        $eligible = DesignJob::query()->whereKey($designJob->id)
            ->where('status', '!=', 'cancelled')
            ->whereHas('enquiry.deliverables', fn ($query) => $query->whereNull('archived_at'))
            ->exists();
        abort_unless($eligible, 404);

        $data = $request->validate([
            'printing_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'machine_asset_id' => ['sometimes', 'nullable', 'integer', $this->printingMachineRule()],
            'operator_id' => ['sometimes', 'nullable', 'integer', $this->printingOperatorRule()],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages(['schedule' => ['Supply a printing date, printer, or operator.']]);
        }
        if (array_key_exists('operator_id', $data)) {
            abort_unless(PrintingAccess::allows($request->user(), 'printing.jobs.assign', true), 403);
        }

        $schedule = PrintProjectSchedule::query()->firstOrNew(['design_job_id' => $designJob->id]);
        $schedule->fill($data);
        $schedule->updated_by = $request->user()?->id;
        $schedule->save();
        $schedule->load(['machine:id,name', 'operator:id,name']);

        return response()->json(['data' => [
            'design_job_id' => $designJob->id,
            'printing_date' => $schedule->printing_date?->format('Y-m-d'),
            'machine_asset_id' => $schedule->machine_asset_id,
            'machine_name' => $schedule->machine?->name,
            'operator_id' => $schedule->operator_id,
            'operator_name' => $schedule->operator?->name,
        ]]);
    }

    public function timeline(Request $request): JsonResponse
    {
        $dates = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (Carbon::parse($dates['from'])->diffInDays(Carbon::parse($dates['to'])) > 31) {
            throw ValidationException::withMessages(['to' => ['Choose a range of 31 days or less.']]);
        }

        $setupDate = ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id');
        $projects = DesignJob::query()
            ->select('design_jobs.*')
            ->selectSub($setupDate, 'project_setup_date')
            ->join('print_project_schedules as schedule', 'schedule.design_job_id', '=', 'design_jobs.id')
            ->whereBetween('schedule.printing_date', [$dates['from'], $dates['to']])
            ->where('design_jobs.status', '!=', 'cancelled')
            ->with([
                'enquiry.client',
                'client',
                'enquiry.deliverables' => fn ($query) => $query->whereNull('archived_at')->orderBy('sort_order')->orderBy('id'),
                'items.assignedUser:id,name',
                'printingSchedule.machine:id,name',
                'printingSchedule.operator:id,name',
            ])
            ->orderBy('schedule.printing_date')
            ->orderBy('design_jobs.id')
            ->get();

        return response()->json(['data' => UpcomingProjectResource::collection($projects)]);
    }

    public function report(Request $request): JsonResponse
    {
        $dates = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'unscheduled_scope' => ['sometimes', Rule::in(['period', 'upcoming'])],
        ]);

        if (Carbon::parse($dates['from'])->diffInDays(Carbon::parse($dates['to'])) > 31) {
            throw ValidationException::withMessages(['to' => ['Choose a range of 31 days or less.']]);
        }

        $setupDate = ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id');
        $unscheduledScope = $dates['unscheduled_scope'] ?? 'period';
        $projects = DesignJob::query()
            ->select('design_jobs.*')
            ->selectSub($setupDate, 'project_setup_date')
            ->leftJoin('print_project_schedules as schedule', 'schedule.design_job_id', '=', 'design_jobs.id')
            ->where('design_jobs.status', '!=', 'cancelled')
            ->whereHas('enquiry.deliverables', fn ($query) => $query->whereNull('archived_at'))
            ->where(function ($query) use ($dates, $setupDate, $unscheduledScope) {
                $query->whereBetween('schedule.printing_date', [$dates['from'], $dates['to']])
                    ->orWhere(function ($unscheduled) use ($dates, $setupDate, $unscheduledScope) {
                        $unscheduled->whereNull('schedule.printing_date');
                        if ($unscheduledScope === 'upcoming') {
                            ProjectSetupSchedule::filterDateScope($unscheduled, $setupDate, 'upcoming');
                        } else {
                            $unscheduled->where($setupDate, '>=', $dates['from'])
                                ->where($setupDate, '<=', $dates['to']);
                        }
                    });
            })
            ->with([
                'enquiry.client',
                'client',
                'enquiry.deliverables' => fn ($query) => $query->whereNull('archived_at')->orderBy('sort_order')->orderBy('id'),
                'items.assignedUser:id,name',
                'printingSchedule.machine:id,name',
                'printingSchedule.operator:id,name',
            ])
            ->orderBy('design_jobs.id')
            ->get();

        return response()->json(['data' => UpcomingProjectResource::collection($projects)]);
    }

    private function printingOperatorRule()
    {
        $departmentIds = Department::query()->where('name', 'Printing')->pluck('id');
        $operatorIds = User::query()->active()
            ->where(fn ($query) => $query
                ->whereIn('department_id', $departmentIds)
                ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'Printing')))
            ->pluck('id');

        return Rule::exists('users', 'id')->where(fn ($query) => $query
            ->where('is_active', true)
            ->whereIn('id', $operatorIds));
    }

    private function printingMachineRule()
    {
        $categoryIds = AssetCategory::query()->where('name', 'like', '%print%')->pluck('id');
        $departmentIds = Department::query()->where('name', 'like', '%print%')->pluck('id');

        return Rule::exists('assets', 'id')->where(fn ($query) => $query
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($inner) => $inner
                ->where('name', 'like', '%print%')
                ->orWhere('category', 'like', '%print%')
                ->orWhere('subcategory', 'like', '%print%')
                ->orWhereIn('category_id', $categoryIds)
                ->orWhereIn('department_id', $departmentIds)));
    }
}
