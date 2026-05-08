<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class StatusFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }

        if (request()->boolean('assigned_to_me')) {
            $query->where('assigned_user_id', Auth::id());
        }

        if (request()->filled('priority')) {
            $query->where('priority', request('priority'));
        }

        return $next($query);
    }
}
