<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Printing\Resources\UpcomingPrintJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpcomingPrintJobController extends Controller
{
    private const STATUSES = ['pending', 'in_design', 'awaiting_client_approval', 'client_changes_requested', 'done'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasRole = $user->hasRole(['Super Admin', 'Admin', 'Printing']);
        $department = $hasRole ? null : ($user->department?->name ?? $user->employee?->department?->name);
        abort_unless($hasRole || $department === 'Printing', 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = DesignItem::query()
            ->where('stream', DesignItem::STREAM_GRAPHIC)
            ->whereIn('status', self::STATUSES)
            ->whereHas('job', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with(['job.enquiry.client', 'job.client', 'assignedUser', 'printMaterial'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(trim($filters['search'] ?? ''), function ($query, $search) {
                $term = '%'.$search.'%';
                $query->where(fn ($match) => $match
                    ->where('title', 'like', $term)
                    ->orWhereHas('job', fn ($job) => $job
                        ->where('title', 'like', $term)
                        ->orWhere('job_number', 'like', $term)
                        ->orWhereHas('client', fn ($client) => $client->where('full_name', 'like', $term))
                        ->orWhereHas('enquiry', fn ($enquiry) => $enquiry
                            ->where('title', 'like', $term)
                            ->orWhereHas('client', fn ($client) => $client->where('full_name', 'like', $term)))));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($items->through(fn ($item) => new UpcomingPrintJobResource($item)));
    }
}
