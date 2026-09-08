<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Support\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(DesignAccess::userCanAccessLeadViews($request->user()), 403, 'Only Design leads can view the Design dashboard.');

        $designerId = $request->integer('designer_id') ?: null;
        $itemScope = DesignItem::query();
        if ($designerId) {
            $itemScope->where('assigned_to', $designerId);
        }

        $statusCounts = (clone $itemScope)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $streamCounts = (clone $itemScope)
            ->selectRaw('stream, count(*) as total')
            ->groupBy('stream')
            ->pluck('total', 'stream');

        $designerMetrics = DesignItem::query()
            ->with('assignedUser:id,name,email')
            ->selectRaw('assigned_to, count(*) as total')
            ->selectRaw("sum(case when status in ('pending', 'in_design', 'awaiting_client_approval', 'client_changes_requested') then 1 else 0 end) as active")
            ->selectRaw("sum(case when status = 'done' then 1 else 0 end) as done")
            ->selectRaw("sum(case when status = 'print_ready' then 1 else 0 end) as print_ready")
            ->selectRaw("sum(case when status = 'production_ready' then 1 else 0 end) as production_ready")
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn (DesignItem $item) => [
                'designer_id' => $item->assigned_to,
                'designer_name' => $item->assignedUser?->name ?? 'Unassigned',
                'total' => (int) $item->total,
                'active' => (int) $item->active,
                'done' => (int) $item->done,
                'print_ready' => (int) $item->print_ready,
                'production_ready' => (int) $item->production_ready,
            ])
            ->values();

        $handoffCounts = (clone $itemScope)
            ->join('design_handoffs', 'design_handoffs.design_item_id', '=', 'design_items.id')
            ->selectRaw('design_handoffs.status as handoff_status, count(*) as total')
            ->groupBy('design_handoffs.status')
            ->pluck('total', 'handoff_status');

        $activeStatuses = ['pending', 'in_design', 'awaiting_client_approval', 'client_changes_requested'];

        return response()->json([
            'data' => [
                'active_jobs' => DesignJob::whereNotIn('status', ['cancelled', 'done'])->count(),
                'total_items' => (clone $itemScope)->count(),
                'active_items' => (clone $itemScope)->whereIn('status', $activeStatuses)->count(),
                'graphic_in_progress' => (clone $itemScope)->where('stream', 'graphic')->whereIn('status', $activeStatuses)->count(),
                'structural_in_progress' => (clone $itemScope)->where('stream', 'structural')->whereIn('status', $activeStatuses)->count(),
                'awaiting_client_approval' => (int) ($statusCounts['awaiting_client_approval'] ?? 0),
                'client_changes_requested' => (int) ($statusCounts['client_changes_requested'] ?? 0),
                'done' => (int) ($statusCounts['done'] ?? 0),
                'print_ready' => (int) ($statusCounts['print_ready'] ?? 0),
                'production_ready' => (int) ($statusCounts['production_ready'] ?? 0),
                'rejected_handoffs' => (int) ($handoffCounts['rejected'] ?? 0),
                'status_counts' => $statusCounts,
                'stream_counts' => $streamCounts,
                'handoff_counts' => $handoffCounts,
                'designer_metrics' => $designerMetrics,
            ],
        ]);
    }
}
