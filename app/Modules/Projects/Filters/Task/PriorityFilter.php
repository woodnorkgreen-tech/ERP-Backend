<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class PriorityFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (!request()->filled('priority')) {
            return $next($query);
        }

        $query->where('priority', request('priority'));

        return $next($query);
    }
}
