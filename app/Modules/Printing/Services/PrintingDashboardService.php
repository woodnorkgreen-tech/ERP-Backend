<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobConsumption;
use App\Modules\Printing\Models\PrintManualConsumption;
use App\Modules\Printing\Models\PrintRoll;
use Illuminate\Http\Request;

class PrintingDashboardService
{
    public function summary(Request $request): array
    {
        $jobs = $this->filteredJobs($request);

        return [
            'kpis' => [
                'queued_jobs' => (clone $jobs)->where('status', 'queued')->count(),
                'in_progress_jobs' => (clone $jobs)->whereIn('status', ['preflight', 'ready_to_print', 'printing', 'printed'])->count(),
                'completed_jobs' => (clone $jobs)->where('status', 'completed')->count(),
                'reprints' => (clone $jobs)->where('order_type', 'reprint')->count(),
                'low_rolls' => PrintRoll::where('status', 'active')->where('remaining_length_m', '<=', 5)->count(),
            ],
            'material_usage' => [
                'calculated_sqm' => (float) (clone $this->filteredConsumptions($request))->sum('calculated_sqm'),
                'calculated_running_m' => (float) (clone $this->filteredConsumptions($request))->sum('calculated_running_m'),
                'actual_running_m' => (float) (clone $this->filteredConsumptions($request))->sum('actual_running_m'),
                'manual_running_m' => (float) PrintManualConsumption::sum('quantity_m'),
            ],
            'status_breakdown' => $this->filteredJobs($request)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status'),
            'reprint_reasons' => $this->filteredJobs($request)->where('order_type', 'reprint')
                ->selectRaw('COALESCE(reprint_reason, "Unspecified") as reason, COUNT(*) as count')
                ->groupBy('reason')
                ->orderByDesc('count')
                ->limit(8)
                ->get(),
            'machine_utilization' => $this->filteredJobs($request)->selectRaw('COALESCE(machine_name_snapshot, "Unassigned") as machine, COUNT(*) as jobs')
                ->groupBy('machine')
                ->orderByDesc('jobs')
                ->limit(10)
                ->get(),
            'operator_output' => $this->filteredJobs($request)
                ->leftJoin('users', 'print_jobs.operator_id', '=', 'users.id')
                ->leftJoin('print_job_consumptions', 'print_jobs.id', '=', 'print_job_consumptions.print_job_id')
                ->selectRaw('COALESCE(users.name, "Unassigned") as operator, COUNT(DISTINCT print_jobs.id) as jobs, COALESCE(SUM(print_job_consumptions.actual_running_m), 0) as actual_running_m')
                ->groupBy('operator')
                ->orderByDesc('jobs')
                ->limit(10)
                ->get(),
            'project_usage' => $this->projectUsage($request),
        ];
    }

    public function projectUsage(Request $request)
    {
        return PrintJob::query()
            ->leftJoin('print_job_consumptions', 'print_jobs.id', '=', 'print_job_consumptions.print_job_id')
            ->selectRaw('
                print_jobs.project_enquiry_id,
                print_jobs.project_id,
                print_jobs.job_number,
                COALESCE(print_jobs.project_name, print_jobs.title) as project_name,
                COUNT(DISTINCT print_jobs.id) as print_jobs_count,
                COALESCE(SUM(print_job_consumptions.calculated_sqm), 0) as calculated_sqm,
                COALESCE(SUM(print_job_consumptions.calculated_running_m), 0) as calculated_running_m,
                COALESCE(SUM(print_job_consumptions.actual_running_m), 0) as actual_running_m,
                COALESCE(SUM(print_job_consumptions.actual_running_m - print_job_consumptions.calculated_running_m), 0) as variance_m,
                SUM(CASE WHEN print_jobs.order_type = "reprint" THEN 1 ELSE 0 END) as reprints
            ')
            ->when($request->filled('project_enquiry_id'), fn ($q) => $q->where('print_jobs.project_enquiry_id', $request->integer('project_enquiry_id')))
            ->when($request->filled('operator_id'), fn ($q) => $q->where('print_jobs.operator_id', $request->integer('operator_id')))
            ->when($request->filled('machine_asset_id'), fn ($q) => $q->where('print_jobs.machine_asset_id', $request->integer('machine_asset_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('print_jobs.status', $request->string('status')))
            ->when($request->filled('order_type'), fn ($q) => $q->where('print_jobs.order_type', $request->string('order_type')))
            ->when($request->boolean('reprints_only'), fn ($q) => $q->where('print_jobs.order_type', 'reprint'))
            ->when($request->filled('material_id'), fn ($q) => $q->where('print_job_consumptions.material_id', $request->integer('material_id')))
            ->when($request->filled('print_roll_id'), fn ($q) => $q->where('print_job_consumptions.print_roll_id', $request->integer('print_roll_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereRaw('DATE(COALESCE(print_jobs.completed_at, print_jobs.scheduled_at, print_jobs.created_at)) >= ?', [$request->date('date_from')->format('Y-m-d')]))
            ->when($request->filled('date_to'), fn ($q) => $q->whereRaw('DATE(COALESCE(print_jobs.completed_at, print_jobs.scheduled_at, print_jobs.created_at)) <= ?', [$request->date('date_to')->format('Y-m-d')]))
            ->groupBy('print_jobs.project_enquiry_id', 'print_jobs.project_id', 'print_jobs.job_number', 'project_name')
            ->orderByDesc('actual_running_m')
            ->limit((int) $request->get('limit', 20))
            ->get();
    }

    private function filteredJobs(Request $request)
    {
        return $this->applyJobFilters(PrintJob::query(), $request);
    }

    private function filteredConsumptions(Request $request)
    {
        return PrintJobConsumption::query()
            ->when($request->filled('material_id'), fn ($q) => $q->where('material_id', $request->integer('material_id')))
            ->when($request->filled('print_roll_id'), fn ($q) => $q->where('print_roll_id', $request->integer('print_roll_id')))
            ->whereHas('job', fn ($q) => $this->applyJobFilters($q, $request));
    }

    private function applyJobFilters($query, Request $request)
    {
        return $query
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('order_type'), fn ($q) => $q->where('order_type', $request->string('order_type')))
            ->when($request->boolean('reprints_only'), fn ($q) => $q->where('order_type', 'reprint'))
            ->when($request->filled('operator_id'), fn ($q) => $q->where('operator_id', $request->integer('operator_id')))
            ->when($request->filled('machine_asset_id'), fn ($q) => $q->where('machine_asset_id', $request->integer('machine_asset_id')))
            ->when($request->filled('project_enquiry_id'), fn ($q) => $q->where('project_enquiry_id', $request->integer('project_enquiry_id')))
            ->when($request->filled('material_id'), fn ($q) => $q->whereHas('consumptions', fn ($inner) => $inner->where('material_id', $request->integer('material_id'))))
            ->when($request->filled('print_roll_id'), fn ($q) => $q->whereHas('consumptions', fn ($inner) => $inner->where('print_roll_id', $request->integer('print_roll_id'))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, scheduled_at, created_at)) >= ?', [$request->date('date_from')->format('Y-m-d')]))
            ->when($request->filled('date_to'), fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, scheduled_at, created_at)) <= ?', [$request->date('date_to')->format('Y-m-d')]));
    }
}
