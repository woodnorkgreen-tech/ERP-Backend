<?php

namespace App\Modules\Design\Support;

use App\Modules\Design\Models\DesignJob;
use App\Support\ProjectSetupSchedule;
use Illuminate\Database\Eloquent\Builder;

class DesignSchedule
{
    public const CLOSED_JOBS = ['done', 'handed_off', 'cancelled'];
    public const CLOSED_ITEMS = ['done', 'print_ready', 'production_ready', 'handed_off', 'cancelled'];

    public static function itemDateQuery(): Builder
    {
        return DesignJob::query()
            ->selectSub(ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id'), 'setup_date')
            ->whereColumn('design_jobs.id', 'design_items.design_job_id')->limit(1);
    }

    public static function overdueJobs(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_JOBS)
            ->where(ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id'), '<', now('Africa/Nairobi')->toDateString());
    }

    public static function overdueItems(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_ITEMS)
            ->whereHas('job', fn ($job) => $job->whereNotIn('status', self::CLOSED_JOBS))
            ->where(self::itemDateQuery(), '<', now('Africa/Nairobi')->toDateString());
    }
}
