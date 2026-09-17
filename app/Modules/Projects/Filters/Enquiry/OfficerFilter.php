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
            $raw = request('project_officer_id');
            $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
            $ids = array_filter(array_map('trim', $ids), fn ($id) => $id !== '');

            if (count($ids) > 0) {
                $query->whereIn('project_officer_id', $ids);
            }
        }

        return $next($query);
    }
}
