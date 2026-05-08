<?php

namespace App\Modules\Projects\Filters\Enquiry;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class ViewTypeFilter
{
    /**
     * Preset keys that belong to the Internal (non-profit) pipeline.
     * Any enquiry whose workflow_preset_type is in this list is treated as
     * an internal job and excluded from the external view, and vice versa.
     */
    private const INTERNAL_PRESETS = [
        'internal_job',
        'internal_prod',
        'sponsorship',
    ];

    public function handle(Builder $query, Closure $next)
    {
        $view = request('view', 'enquiries');

        $completedOnlyStatuses = ['completed'];
        $cancelledStatuses = ['cancelled'];
        $closedStatuses = array_merge($completedOnlyStatuses, $cancelledStatuses);
        $activeProjectStatuses = ['quote_approved', 'planning', 'in_progress'];
        $receivableStatuses = ['quote_approved', 'awaiting_deposit', 'planning', 'in_progress', 'completed'];

        // Apply View Filter
        match ($view) {
            'completed'              => $query->whereIn('status', $completedOnlyStatuses),
            'canceled', 'cancelled' => $query->whereIn('status', $cancelledStatuses),
            'awaiting_deposit'       => $query->where('status', 'awaiting_deposit'),
            'projects'               => $query->whereIn('status', $activeProjectStatuses),
            'receivables'            => $query->whereIn('status', $receivableStatuses),
            default                  => $query->whereNotIn('status', array_merge($activeProjectStatuses, $closedStatuses, ['awaiting_deposit'])),
        };

        // ── Pipeline Separation (Internal vs External) ────────────────────────
        // Internal jobs are identified by their workflow_preset_type.
        // Enquiries with no preset (legacy/unset) are treated as external.
        if (request()->boolean('is_non_profit')) {
            // Internal view: only show rows whose preset is in the internal list
            $query->whereIn('workflow_preset_type', self::INTERNAL_PRESETS);
        } else {
            // External view: exclude all internal presets; include NULL (legacy rows)
            $query->where(function ($q) {
                $q->whereNotIn('workflow_preset_type', self::INTERNAL_PRESETS)
                  ->orWhereNull('workflow_preset_type');
            });
        }

        return $next($query);
    }
}
