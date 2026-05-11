<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class AssignedUserFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (!request()->filled('assigned_user_id')) {
            return $next($query);
        }

        $userId = request('assigned_user_id');
        
        $query->where(function ($q) use ($userId) {
            $q->where('assigned_user_id', $userId)
              ->orWhereHas('assignedUsers', function ($sub) use ($userId) {
                  $sub->where('users.id', $userId);
              });
        });

        return $next($query);
    }
}
