<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Http\JsonResponse;

class ProjectDesignProgressController extends Controller
{
    public function __invoke(ProjectEnquiry $enquiry): JsonResponse
    {
        // Project tasks are visible to authenticated project users. This endpoint
        // exposes only aggregate progress, not Design job management details.
        $jobs = DesignJob::query()->where('project_enquiry_id', $enquiry->id)->pluck('id');
        $counts = DesignItem::query()
            ->whereIn('design_job_id', $jobs)
            ->where('status', '!=', 'not_required')
            ->selectRaw('stream, status, COUNT(*) AS total')
            ->groupBy('stream', 'status')
            ->get();

        $streams = [];
        foreach ([DesignItem::STREAM_GRAPHIC, DesignItem::STREAM_STRUCTURAL] as $stream) {
            $streamCounts = $counts->where('stream', $stream);
            $total = (int) $streamCounts->sum('total');
            $completed = (int) $streamCounts->whereIn('status', DesignItem::COMPLETED_STATUSES)->sum('total');
            $streams[$stream] = [
                'total' => $total,
                'completed' => $completed,
                'progress_percent' => $total ? (int) round($completed / $total * 100) : 0,
            ];
        }

        $total = $streams['graphic']['total'] + $streams['structural']['total'];
        $completed = $streams['graphic']['completed'] + $streams['structural']['completed'];

        return response()->json(['data' => [
            'linked' => $jobs->isNotEmpty(),
            'design_job_id' => $jobs->count() === 1 ? $jobs->first() : null,
            'total' => $total,
            'completed' => $completed,
            'progress_percent' => $total ? (int) round($completed / $total * 100) : 0,
            'streams' => $streams,
        ]]);
    }
}
