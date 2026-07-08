<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientService\Models\NcrReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NcrController extends Controller
{
    /**
     * GET clientservice/ncr
     * Paginated list of NCR reports with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = NcrReport::with(['enquiry', 'raisedBy', 'resolvedBy'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('assigned_department')) {
            $query->where('assigned_department', 'like', '%' . $request->assigned_department . '%');
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%")
                  ->orWhereHas('enquiry', fn ($qe) =>
                      $qe->where('title', 'like', "%{$s}%")
                         ->orWhere('job_number', 'like', "%{$s}%")
                  );
            });
        }

        $paginator = $query->paginate(15);

        $data = collect($paginator->items())->map(fn (NcrReport $n) => [
            'id'                  => $n->id,
            'project_enquiry_id'  => $n->project_enquiry_id,
            'handover_survey_id'  => $n->handover_survey_id,
            'job_number'          => $n->enquiry?->job_number,
            'project_title'       => $n->enquiry?->title,
            'raised_by_name'      => $n->raisedBy?->name,
            'assigned_department' => $n->assigned_department,
            'title'               => $n->title,
            'category'            => $n->category,
            'description'         => $n->description,
            'root_cause'          => $n->root_cause,
            'corrective_action'   => $n->corrective_action,
            'status'              => $n->status,
            'resolved_by_name'    => $n->resolvedBy?->name,
            'resolved_at'         => $n->resolved_at?->toISOString(),
            'created_at'          => $n->created_at->toISOString(),
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * PATCH clientservice/ncr/{id}
     * Update NCR status, root cause, corrective action, and resolution.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ncr = NcrReport::find($id);

        if (!$ncr) {
            return response()->json(['message' => 'NCR not found.'], 404);
        }

        $validated = $request->validate([
            'status'             => ['sometimes', Rule::in(NcrReport::STATUSES)],
            'assigned_department'=> ['sometimes', 'nullable', 'string', 'max:100'],
            'root_cause'         => ['sometimes', 'nullable', 'string'],
            'corrective_action'  => ['sometimes', 'nullable', 'string'],
        ]);

        $ncr->fill($validated);

        // Auto-stamp resolution when moving to resolved/closed
        if (isset($validated['status']) && in_array($validated['status'], ['resolved', 'closed'])) {
            if (!$ncr->resolved_at) {
                $ncr->resolved_by = Auth::id();
                $ncr->resolved_at = now();
            }
        }

        $ncr->save();

        return response()->json([
            'message' => 'NCR updated.',
            'status'  => $ncr->status,
            'id'      => $ncr->id,
        ]);
    }
}
