<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\HandoverSurvey;
use App\Modules\ClientService\Models\NcrReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HandoverReviewController extends Controller
{
    /**
     * CS Lead submits a review decision on a submitted handover survey.
     *
     * POST clientservice/handovers/{id}/review
     *
     * Body:
     *   review_status  string  required  approved_positive|ncr_triggered
     *   review_notes   string  optional  General remarks
     *   ncr            object  required when review_status=ncr_triggered
     *     .title               string  required
     *     .category            string  required  (quality|delivery|communication|design|installation|other)
     *     .description         string  required
     *     .assigned_department string  optional
     *     .root_cause          string  optional
     *     .corrective_action   string  optional
     */
    public function review(Request $request, int $id): JsonResponse
    {
        if (!Auth::user()->hasPermissionTo(Permissions::CLIENT_HANDOVER_REVIEW)) {
            return response()->json(['message' => 'Unauthorized: CS Lead permission required to review handover surveys.'], 403);
        }

        $survey = HandoverSurvey::with(['task.enquiry', 'ncrReport'])->find($id);

        if (!$survey || !$survey->submitted) {
            return response()->json(['message' => 'Handover survey not found or not yet submitted.'], 404);
        }

        if ($survey->review_status === 'approved_positive') {
            return response()->json(['message' => 'This survey has already been reviewed and approved.'], 422);
        }

        $validated = $request->validate([
            'review_status'           => ['required', Rule::in(['approved_positive', 'ncr_triggered'])],
            'review_notes'            => ['nullable', 'string', 'max:2000'],
            'ncr.title'               => ['required_if:review_status,ncr_triggered', 'nullable', 'string', 'max:255'],
            'ncr.category'            => ['required_if:review_status,ncr_triggered', 'nullable', Rule::in(NcrReport::CATEGORIES)],
            'ncr.description'         => ['required_if:review_status,ncr_triggered', 'nullable', 'string'],
            'ncr.assigned_department' => ['nullable', 'string', 'max:100'],
            'ncr.root_cause'          => ['nullable', 'string'],
            'ncr.corrective_action'   => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($validated, $survey) {
            $ncr = null;

            if ($validated['review_status'] === 'ncr_triggered') {
                $enquiryId = $survey->task?->enquiry?->id;

                if (!$enquiryId) {
                    abort(422, 'Cannot create NCR: survey is not linked to a project enquiry.');
                }

                $ncr = NcrReport::updateOrCreate(
                    ['handover_survey_id' => $survey->id],
                    [
                        'project_enquiry_id'  => $enquiryId,
                        'raised_by'           => Auth::id(),
                        'title'               => $validated['ncr']['title'],
                        'category'            => $validated['ncr']['category'],
                        'description'         => $validated['ncr']['description'],
                        'assigned_department' => $validated['ncr']['assigned_department'] ?? null,
                        'root_cause'          => $validated['ncr']['root_cause'] ?? null,
                        'corrective_action'   => $validated['ncr']['corrective_action'] ?? null,
                        'status'              => 'open',
                    ]
                );
            }

            $survey->review_status = $validated['review_status'];
            $survey->review_notes  = $validated['review_notes'] ?? null;
            $survey->reviewed_by   = Auth::id();
            $survey->reviewed_at   = now();
            $survey->save();

            return response()->json([
                'message'       => 'Review saved.',
                'review_status' => $survey->review_status,
                'reviewed_at'   => $survey->reviewed_at->toISOString(),
                'ncr_id'        => $ncr?->id,
            ]);
        });
    }
}
