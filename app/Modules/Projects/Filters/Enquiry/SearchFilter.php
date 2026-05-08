<?php

namespace App\Modules\Projects\Filters\Enquiry;

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
              ->orWhere('enquiry_number', 'like', "%{$search}%")
              ->orWhere('job_number', 'like', "%{$search}%")
              ->orWhereHas('client', function ($clientQuery) use ($search) {
                  $clientQuery->where('full_name', 'like', "%{$search}%");
              })
              ->orWhere('contact_person', 'like', "%{$search}%");
        });

        return $next($query);
    }
}
