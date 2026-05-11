<?php

namespace App\Modules\Projects\Filters\Task;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class SearchFilter
{
    public function handle(Builder $query, Closure $next)
    {
        if (!request()->filled('search')) {
            return $next($query);
        }

        $search = request('search');
        $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
              ->orWhereHas('enquiry', function ($enquiryQuery) use ($search) {
                  $enquiryQuery->where('title', 'like', "%{$search}%")
                               ->orWhere('enquiry_number', 'like', "%{$search}%")
                               ->orWhere('job_number', 'like', "%{$search}%");
              });
        });

        return $next($query);
    }
}
