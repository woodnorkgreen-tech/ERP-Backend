<?php

namespace App\Modules\Projects\Filters\Enquiry;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class OfficerFilter
{
    /**
     * Handle the filter.
     *
     * @param Builder $query
     * @param Closure $next
     * @return Builder
     */
    public function handle(Builder $query, Closure $next)
    {
        if (request()->filled('project_officer_id')) {
            $query->where('project_officer_id', request('project_officer_id'));
        }

        return $next($query);
    }
}
