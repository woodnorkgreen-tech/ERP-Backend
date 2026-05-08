<?php

namespace App\Modules\Projects\Filters\Enquiry;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class StatusFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }

        if (request()->filled('client_id')) {
            $query->where('client_id', request('client_id'));
        }

        if (request()->filled('department_id')) {
            $query->where('department_id', request('department_id'));
        }

        return $next($query);
    }
}
