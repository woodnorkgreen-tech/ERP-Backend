<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobConsumption;
use App\Modules\Printing\Models\PrintManualConsumption;
use App\Modules\Printing\Models\PrintRoll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintingDashboardService
{
    private const IN_PROGRESS_STATUSES = [
        'preflight',
        'ready_to_print',
        'printing',
        'printed',
        'qc_failed',
        'reprint_required',
    ];

    public function summary(Request $request): array
    {
        $jobs = $this->filteredJobs($request);

        return [
            'kpis' => [
                'total_jobs' => (clone $jobs)->count(),
                'queued_jobs' => (clone $jobs)->where('status', 'queued')->count(),
                'in_progress_jobs' => (clone $jobs)->whereIn('status', self::IN_PROGRESS_STATUSES)->count(),
                'needs_attention_jobs' => (clone $jobs)->whereIn('status', ['qc_failed', 'reprint_required'])->count(),
                'completed_jobs' => (clone $jobs)->where('status', 'completed')->count(),
                'completed_today' => (clone $jobs)->where('status', 'completed')->whereDate('completed_at', today())->count(),
                'completed_this_week' => (clone $jobs)->where('status', 'completed')->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'completed_this_month' => (clone $jobs)->where('status', 'completed')->whereBetween('completed_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
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
        $projectGroup = "CASE WHEN print_jobs.origin = 'historical_import'
            THEN CONCAT('history:', COALESCE(NULLIF(TRIM(print_jobs.project_name), ''), 'Unassigned project'))
            ELSE CONCAT('live:', COALESCE(print_jobs.project_enquiry_id, 0), ':', COALESCE(print_jobs.project_id, 0), ':', COALESCE(print_jobs.job_number, '')) END";
        return PrintJob::query()
            ->leftJoin('print_job_consumptions', 'print_jobs.id', '=', 'print_job_consumptions.print_job_id')
            ->selectRaw('
                MAX(print_jobs.project_enquiry_id) as project_enquiry_id,
                MAX(print_jobs.project_id) as project_id,
                CASE WHEN MAX(print_jobs.origin) = "historical_import" THEN NULL ELSE MAX(print_jobs.job_number) END as job_number,
                CASE WHEN MAX(print_jobs.origin) = "historical_import"
                    THEN COALESCE(MAX(NULLIF(TRIM(print_jobs.project_name), "")), "Unassigned project")
                    ELSE COALESCE(MAX(print_jobs.project_name), MAX(print_jobs.title)) END as project_name,
                COUNT(DISTINCT print_jobs.id) as print_jobs_count,
                COALESCE(SUM(print_job_consumptions.calculated_sqm), 0) as calculated_sqm,
                COALESCE(SUM(print_job_consumptions.calculated_running_m), 0) as calculated_running_m,
                COALESCE(SUM(print_job_consumptions.actual_running_m), 0) as actual_running_m,
                COALESCE(SUM(print_job_consumptions.actual_running_m - print_job_consumptions.calculated_running_m), 0) as variance_m,
                COUNT(DISTINCT CASE WHEN print_jobs.order_type = "reprint" THEN print_jobs.id END) as reprints
            ')
            ->when($request->input('source') === 'historical', fn ($q) => $q->where('print_jobs.origin', 'historical_import'))
            ->when($request->input('source') === 'live', fn ($q) => $q->where('print_jobs.origin', '!=', 'historical_import'))
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
            ->groupByRaw($projectGroup)
            ->orderByDesc('actual_running_m')
            ->limit((int) $request->get('limit', 20))
            ->get();
    }

    public function monthlyConsumption(array $filters): array
    {
        $date = "CASE WHEN j.origin = 'historical_import' THEN j.completed_at ELSE c.created_at END";
        $rawMaterial = "COALESCE(NULLIF(c.material_family, ''), NULLIF(c.material_name_snapshot, ''), NULLIF(lm.material_name, ''), 'Unknown material')";
        $material = "CASE
            WHEN LOWER({$rawMaterial}) IN ('matt white sticker', 'matte sticker') THEN 'Matte Sticker'
            WHEN LOWER({$rawMaterial}) IN ('glosy sticker white', 'gloss sticker') THEN 'Gloss Sticker'
            ELSE {$rawMaterial} END";
        $project = "COALESCE(NULLIF(TRIM(j.project_name), ''), 'Unassigned project')";
        $monthExpression = "DATE_FORMAT({$date}, '%Y-%m')";
        $weekExpression = "DATE_SUB(DATE({$date}), INTERVAL WEEKDAY({$date}) DAY)";
        $period = $filters['period'] ?? 'month';

        $base = DB::table('print_job_consumptions as c')
            ->join('print_jobs as j', 'j.id', '=', 'c.print_job_id')
            ->leftJoin('library_materials as lm', 'lm.id', '=', 'c.material_id')
            ->leftJoin('print_history_source_rows as source', 'source.print_job_id', '=', 'j.id')
            ->whereRaw("{$date} IS NOT NULL")
            ->when(($filters['source'] ?? 'all') === 'historical', fn ($query) => $query->where('j.origin', 'historical_import'))
            ->when(($filters['source'] ?? 'all') === 'live', fn ($query) => $query->where('j.origin', '!=', 'historical_import'))
            ->when(!empty($filters['project']), fn ($query) => $query->whereRaw("{$project} LIKE ?", ['%' . $filters['project'] . '%']));

        $availableMaterials = (clone $base)->selectRaw("{$material} as name")
            ->distinct()->orderBy('name')->pluck('name')->all();
        if (!empty($filters['material'])) {
            $base->whereRaw("{$material} = ?", [$filters['material']]);
        }

        $availableMonths = (clone $base)->selectRaw("{$monthExpression} as month")
            ->distinct()->orderByDesc('month')->pluck('month')->all();
        $availableWeeks = (clone $base)->selectRaw("{$weekExpression} as week")
            ->distinct()->orderByDesc('week')->pluck('week')->all();
        $selectedMonth = $period === 'month'
            ? (in_array($filters['month'] ?? null, $availableMonths, true) ? $filters['month'] : ($availableMonths[0] ?? null))
            : null;
        $selectedWeek = $period === 'week'
            ? (in_array($filters['week'] ?? null, $availableWeeks, true) ? $filters['week'] : ($availableWeeks[0] ?? null))
            : null;

        $trendExpression = $period === 'week' ? $weekExpression : $monthExpression;
        $trend = (clone $base)
            ->selectRaw("{$trendExpression} as period, COUNT(DISTINCT j.id) as jobs, COALESCE(SUM(c.actual_running_m), 0) as running_m, COALESCE(SUM(c.calculated_sqm), 0) as area_sqm")
            ->groupByRaw($trendExpression)->orderBy('period')->get();

        $selected = clone $base;
        if ($period === 'month') {
            if ($selectedMonth === null) $selected->whereRaw('1 = 0');
            else $selected->whereRaw("{$monthExpression} = ?", [$selectedMonth]);
        } elseif ($period === 'week') {
            if ($selectedWeek === null) $selected->whereRaw('1 = 0');
            else $selected->whereRaw("{$weekExpression} = ?", [$selectedWeek]);
        }

        $totals = (clone $selected)->selectRaw("COUNT(DISTINCT j.id) as jobs, COUNT(c.id) as usage_rows,
            COALESCE(SUM(c.actual_running_m), 0) as running_m, COALESCE(SUM(c.calculated_sqm), 0) as area_sqm,
            SUM(CASE WHEN c.actual_running_m IS NULL THEN 1 ELSE 0 END) as missing_running_rows,
            COUNT(DISTINCT CASE WHEN {$project} = 'Unassigned project' THEN j.id END) as unassigned_jobs,
            COUNT(DISTINCT CASE WHEN j.origin = 'historical_import' THEN j.id END) as historical_jobs,
            COUNT(DISTINCT CASE WHEN source.date_source = 'previous' THEN j.id END) as inferred_date_jobs")
            ->first();

        // Group the calculated labels as columns. Grouping by the repeated CASE
        // expression fails under the production database's ONLY_FULL_GROUP_BY mode.
        $usageRows = (clone $selected)->selectRaw("j.id as job_id, {$project} as project,
            {$material} as material, c.actual_running_m, c.calculated_sqm");

        $materials = DB::query()->fromSub($usageRows, 'usage_rows')
            ->selectRaw('material, COUNT(DISTINCT job_id) as jobs,
                COUNT(DISTINCT project) as projects, COALESCE(SUM(actual_running_m), 0) as running_m,
                COALESCE(SUM(calculated_sqm), 0) as area_sqm,
                SUM(CASE WHEN actual_running_m IS NULL THEN 1 ELSE 0 END) as missing_running_rows')
            ->groupBy('material')->orderByDesc('running_m')->get();

        $projects = DB::query()->fromSub($usageRows, 'usage_rows')
            ->selectRaw('project, material, COUNT(DISTINCT job_id) as jobs,
                COALESCE(SUM(actual_running_m), 0) as running_m,
                COALESCE(SUM(calculated_sqm), 0) as area_sqm,
                SUM(CASE WHEN actual_running_m IS NULL THEN 1 ELSE 0 END) as missing_running_rows')
            ->groupBy('project', 'material')->orderByDesc('running_m')->get();

        $machines = (clone $selected)->selectRaw("COALESCE(NULLIF(j.machine_name_snapshot, ''), 'Unassigned machine') as machine,
            COUNT(DISTINCT j.id) as jobs, COALESCE(SUM(c.actual_running_m), 0) as running_m")
            ->groupBy('machine')->orderByDesc('running_m')->get();

        return [
            'period' => $period,
            'selected_month' => $selectedMonth,
            'selected_week' => $selectedWeek,
            'available_months' => $availableMonths,
            'available_weeks' => $availableWeeks,
            'available_materials' => $availableMaterials,
            'totals' => $totals,
            'materials' => $materials,
            'projects' => $projects,
            'machines' => $machines,
            'trend' => $trend,
        ];
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
            ->when($request->input('source') === 'historical', fn ($q) => $q->where('origin', 'historical_import'))
            ->when($request->input('source') === 'live', fn ($q) => $q->where('origin', '!=', 'historical_import'))
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
