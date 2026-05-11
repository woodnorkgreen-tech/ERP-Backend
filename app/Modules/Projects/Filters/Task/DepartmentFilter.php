<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class DepartmentFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (request()->filled('department_id')) {
            $query->where('department_id', request('department_id'));
        }

        return $next($query);
    }
}
