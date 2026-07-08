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
        $formallyClosedStatuses = ['closed'];
        $cancelledStatuses     = ['cancelled'];
        $closedStatuses        = array_merge($completedOnlyStatuses, $formallyClosedStatuses, $cancelledStatuses);
        // "Confirmed Jobs / Pending Funds": quote is approved, job number issued, deposit not yet received
        $confirmedJobStatuses  = ['quote_approved', 'awaiting_deposit'];
        // "In Progress / Active projects": deposit received, execution underway
        $activeProjectStatuses = ['planning', 'in_progress'];
        $receivableStatuses    = ['quote_approved', 'awaiting_deposit', 'planning', 'in_progress', 'completed'];

        // Apply View Filter
        match ($view) {
            'completed'              => $query->whereIn('status', $completedOnlyStatuses),
            'closed'                 => $query->whereIn('status', $formallyClosedStatuses),
            'canceled', 'cancelled'  => $query->whereIn('status', $cancelledStatuses),
            // "Confirmed Jobs" tab — catches both quote_approved and awaiting_deposit
            'awaiting_deposit'       => $query->whereIn('status', $confirmedJobStatuses),
            // "In Progress" tab — only execution-phase statuses
            'projects'               => $query->whereIn('status', $activeProjectStatuses),
            'receivables'            => $query->whereIn('status', $receivableStatuses),
            // Default "New Enquiries" — everything before confirmation
            default                  => $query->whereNotIn('status', array_merge($confirmedJobStatuses, $activeProjectStatuses, $closedStatuses)),
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
