<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProjectSetupSchedule
{
    /** Read the parent date live, including jobs linked through projects only. */
    public static function dateQuery(string $enquiryColumn, string $projectColumn): Builder
    {
        return DB::table('project_enquiries')
            ->select('expected_delivery_date')
            ->where(function (Builder $query) use ($enquiryColumn, $projectColumn) {
                $query->whereColumn('project_enquiries.id', $enquiryColumn)
                    ->orWhere(function (Builder $fallback) use ($enquiryColumn, $projectColumn) {
                        $fallback->whereNull($enquiryColumn)
                            ->whereIn('project_enquiries.id', DB::table('projects')
                                ->select('enquiry_id')->whereColumn('projects.id', $projectColumn));
                    });
            })
            ->limit(1);
    }

    public static function dateFor(Model $job): ?string
    {
        // List endpoints select this value in SQL so sorting happens before pagination.
        if (array_key_exists('project_setup_date', $job->getAttributes())) {
            return $job->getAttribute('project_setup_date');
        }

        // Single-record responses must expose the same current date as the lists.
        $enquiry = $job->project_enquiry_id !== null ? $job->enquiry : $job->project?->enquiry;

        return $enquiry?->expected_delivery_date?->format('Y-m-d');
    }
}
