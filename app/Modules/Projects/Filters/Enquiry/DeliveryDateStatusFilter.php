<?php

namespace App\Modules\Projects\Filters\Enquiry;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows the enquiry list to those whose delivery date is still unconfirmed.
 *
 * Filtered server-side rather than over the loaded page, because the whole point
 * of the TBC list is to surface enquiries nobody is looking at — which are
 * exactly the ones sitting several pages deep.
 */
class DeliveryDateStatusFilter
{
    public function handle(Builder $query, Closure $next)
    {
        $status = request('delivery_date_status');

        if ($status === 'tbc') {
            // Read through expected_delivery_date rather than the status column so
            // rows written before the column existed still match.
            $query->whereNull('expected_delivery_date');
        } elseif ($status === 'confirmed') {
            $query->whereNotNull('expected_delivery_date');
        }

        return $next($query);
    }
}
