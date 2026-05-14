<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Constants\EnquiryConstants;

class VisibilityFilter
{
    public function handle(Builder $query, Closure $next)
    {
        $query->authorized();
        return $next($query);
    }
}
