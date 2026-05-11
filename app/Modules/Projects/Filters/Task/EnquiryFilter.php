<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class EnquiryFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (!request()->filled('enquiry_id')) {
            return $next($query);
        }

        $query->where('project_enquiry_id', request('enquiry_id'));

        return $next($query);
    }
}
