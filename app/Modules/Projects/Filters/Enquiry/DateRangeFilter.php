<?php

namespace App\Modules\Projects\Filters\Enquiry;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class DateRangeFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (request()->filled('start_date')) {
            $query->whereDate('created_at', '>=', Carbon::parse(request('start_date')));
        }

        if (request()->filled('end_date')) {
            $query->whereDate('created_at', '<=', Carbon::parse(request('end_date')));
        }

        return $next($query);
    }
}
