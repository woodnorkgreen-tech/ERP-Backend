<?php

namespace App\Modules\Projects\Filters\Enquiry;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class ClientFilter
{
    /**
     * Handle the filter.
     *
     * @param Builder $query
     * @param Closure $next
     * @return Builder
     */
    public function handle(Builder $query, Closure $next)
    {
        if (request()->filled('client_id')) {
            $query->where('client_id', request('client_id'));
        }

        if (request()->filled('client_name')) {
            $name = request('client_name');
            $query->whereHas('client', function ($q) use ($name) {
                $q->where('full_name', 'like', "%{$name}%");
            });
        }

        return $next($query);
    }
}
