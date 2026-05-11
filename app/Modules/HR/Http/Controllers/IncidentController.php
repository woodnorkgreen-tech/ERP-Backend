<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Incident;
use App\Modules\HR\Services\IncidentManagementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class IncidentController extends Controller
{
    protected $incidentService;

    public function __construct(IncidentManagementService $incidentService)
    {
        $this->incidentService = $incidentService;
    }

    /**
     * Get all incidents with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $incidents = $this->incidentService->getIncidents($request);
            
            return response()->json([
                'success' => true,
                'data' => $incidents->items(),
                'meta' => [
                    'current_page' => $incidents->currentPage(),
                    'last_page' => $incidents->lastPage(),
                    'per_page' => $incidents->perPage(),
                    'total' => $incidents->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch incidents: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current user's incidents
     */
    public function myIncidents(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Incident::with(['reporter.employee', 'department', 'reviewer'])
                ->where('reported_by', $user->id);

            // Apply filters
            if ($request->filled('status')) {
                $query->status($request->status);
            }

            $incidents = $query->orderBy('date_reported', 'desc')
                                ->paginate($request->per_page ?? 15);

            // Add computed fields
            $incidents->getCollection()->transform(function ($incident) {
                $incident->reporter_name = $incident->reporter?->name ?? 'Guest';
                $incident->department_name = $incident->department?->name ?? null;
                $incident->days_old = floor($incident->date_reported->diffInDays(now())) + 1;
                return $incident;
            });

            return response()->json([
                'success' => true,
                'data' => $incidents->items(),
                'meta' => [
                    'current_page' => $incidents->currentPage(),
                    'last_page' => $incidents->lastPage(),
                    'per_page' => $incidents->perPage(),
                    'total' => $incidents->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch your incidents: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get incident statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->incidentService->getStatistics();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new incident
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'location' => 'required|string|max:255',
                'incident_datetime' => 'required|date',
                'severity' => 'nullable|in:Low,Medium,High,Critical',
                'incident_types' => 'nullable|array',
                'classification_category' => 'nullable|string',
                'classification_subcategory' => 'nullable|string',
                'classification_other_details' => 'nullable|string',
                'immediate_actions_taken' => 'nullable|string',
                'witnesses' => 'nullable|string',
                'root_cause' => 'nullable|string',
                'corrective_actions' => 'nullable|string',
                'preventive_measures' => 'nullable|string',
                'additional_notes' => 'nullable|string',
                'evidence_paths' => 'nullable|array',
                'equipment_involved' => 'nullable|string',
                'department_id' => 'nullable|exists:departments,id',
                // For guest submissions
                'reporter_name' => 'nullable|string|max:100',
                'reporter_email' => 'nullable|email|max:100',
                'job_title' => 'nullable|string|max:100',
                'contact_info' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $incident = $this->incidentService->createIncident($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Incident reported successfully',
                'data' => $incident,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create incident: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single incident
     */
    public function show(int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);
            $user = auth()->user();

            $isReporter = $incident->reported_by === $user->id;
            $isAdminOrHR = $user->hasAnyRole(['Super Admin', 'Admin', 'HR']);
            $isDeptLeadOrMgr = $user->hasAnyRole(['Lead', 'Manager']);
            $deptId = $user->department_id ?? $user->employee?->department_id;
            $sameDepartment = $deptId && $incident->department_id && ((int) $deptId === (int) $incident->department_id);

            if (!$isAdminOrHR && !$isReporter && !($isDeptLeadOrMgr && $sameDepartment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this incident',
                ], 403);
            }

            $incident = $this->incidentService->getIncidentById($id);

            return response()->json([
                'success' => true,
                'data' => $incident,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch incident: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an incident
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);
            $user = auth()->user();

            // HR/Lead can update broadly; self-service can update limited fields while Open
            $canReview = $user->hasAnyRole(['Super Admin', 'Admin', 'HR', 'Lead']);
            $isReporter = $incident->reported_by === $user->id;
            $isOpen = $incident->status === Incident::STATUS_OPEN;

            if (!$canReview && !($isReporter && $isOpen)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to update this incident',
                ], 403);
            }

            // For self-service editing, restrict fields
            $allowedFields = [
                'description', 'witnesses', 'evidence_paths', 'equipment_involved',
                'immediate_actions_taken', 'additional_notes'
            ];

            if (!$canReview) {
                // Self-service: only allow specific fields
                $data = array_intersect_key($request->all(), array_flip($allowedFields));
            } else {
                // Full editing for reviewers
                $data = $request->all();
            }

            $validator = Validator::make($data, [
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'location' => 'sometimes|string|max:255',
                'incident_datetime' => 'sometimes|date',
                'severity' => 'sometimes|in:Low,Medium,High,Critical',
                'status' => 'sometimes|in:Open,In Progress,Under Review,Resolved,Closed',
                'incident_types' => 'nullable|array',
                'classification_category' => 'nullable|string',
                'classification_subcategory' => 'nullable|string',
                'classification_other_details' => 'nullable|string',
                'immediate_actions_taken' => 'nullable|string',
                'witnesses' => 'nullable|string',
                'root_cause' => 'nullable|string',
                'corrective_actions' => 'nullable|string',
                'preventive_measures' => 'nullable|string',
                'additional_notes' => 'nullable|string',
                'evidence_paths' => 'nullable|array',
                'equipment_involved' => 'nullable|string',
                'department_id' => 'nullable|exists:departments,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $incident = $this->incidentService->updateIncident($incident, $data);

            return response()->json([
                'success' => true,
                'message' => 'Incident updated successfully',
                'data' => $incident,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update incident: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Review an incident (Lead/HR)
     */
    public function review(Request $request, int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);
            
            // Check permission
            $user = auth()->user();
            if (!$incident->canBeReviewedBy($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to review incidents',
                ], 403);
            }
            
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:Open,In Progress,Under Review,Resolved,Closed',
                'short_term_fixes' => 'nullable|string',
                'long_term_measures' => 'nullable|string',
                'responsible_party' => 'nullable|string|max:255',
                'impact_analysis' => 'nullable|string',
                'avoid_recurrence' => 'nullable|string',
                'policy_changes' => 'nullable|string',
                'training_needs' => 'nullable|string',
                'review_notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $incident = $this->incidentService->reviewIncident($incident, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Incident reviewed successfully',
                'data' => $incident,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to review incident: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve/close an incident (HR only)
     */
    public function approve(int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);
            
            // Check permission
            $user = auth()->user();
            if (!$incident->canBeApprovedBy($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to approve incidents',
                ], 403);
            }

            if (!$user->hasAnyRole(['Super Admin', 'Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only HR can approve and close incidents',
                ], 403);
            }

            $incident = $this->incidentService->approveIncident($incident);

            return response()->json([
                'success' => true,
                'message' => 'Incident approved and closed successfully',
                'data' => $incident,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve incident: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add comment to incident
     */
    public function addComment(Request $request, int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'comment' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $comment = $this->incidentService->addComment($incident, $request->comment);

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'data' => $comment,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add comment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get incidents pending review (for leads/HR/admin)
     */
    public function pendingReviews(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user->hasAnyRole(['Super Admin', 'Admin', 'HR', 'Lead'])) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }

            $incidents = $this->incidentService->getPendingReviews($request);

            return response()->json([
                'success' => true,
                'data' => $incidents->items(),
                'meta' => [
                    'current_page' => $incidents->currentPage(),
                    'last_page' => $incidents->lastPage(),
                    'per_page' => $incidents->perPage(),
                    'total' => $incidents->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending reviews: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download incident as PDF
     */
    public function downloadPdf(int $id): \Illuminate\Http\Response
    {
        try {
            $incident = $this->incidentService->getIncidentById($id);

            $pdf = Pdf::loadView('pdf.incident', compact('incident'));

            return $pdf->download("incident-{$incident->id}.pdf");
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, 'Incident not found');
        } catch (\Exception $e) {
            abort(500, 'Failed to generate PDF: ' . $e->getMessage());
        }
    }

    public function userContext(): JsonResponse
    {
        $user = auth()->user();
        $roles = $user->getRoleNames()->toArray();

        $canReview = $user->hasAnyRole(['Super Admin', 'Admin', 'HR', 'Lead']);
        $canApprove = $user->hasAnyRole(['Super Admin', 'Admin', 'HR']);
        $canViewAll = $user->hasAnyRole(['Super Admin', 'Admin', 'HR']);
        $canViewDepartment = $user->hasAnyRole(['Lead', 'Manager']);

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $roles,
                'can_review' => $canReview,
                'can_approve' => $canApprove,
                'can_view_all' => $canViewAll,
                'can_view_department' => $canViewDepartment,
                'department_id' => $user->department_id ?? $user->employee?->department_id,
            ],
        ]);
    }

    /**
     * Upload attachments for an incident
     */
    public function uploadAttachments(Request $request, int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'attachments' => 'required|array|max:10',
                'attachments.*' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $uploadedPaths = [];
            $storagePath = "incidents/{$id}";

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs($storagePath, $filename);
                    $uploadedPaths[] = $path;
                }
            }

            // Update incident evidence_paths
            $currentPaths = $incident->evidence_paths ?? [];
            $incident->update([
                'evidence_paths' => array_merge($currentPaths, $uploadedPaths)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attachments uploaded successfully',
                'data' => $uploadedPaths,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload attachments: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View attachment (for images)
     */
    public function viewAttachment(int $id, string $filename): \Illuminate\Http\Response
    {
        try {
            $incident = Incident::findOrFail($id);
            $evidencePaths = $incident->evidence_paths ?? [];
            
            // Find the full path that ends with this filename
            $path = collect($evidencePaths)->first(function ($p) use ($filename) {
                return str_ends_with($p, $filename);
            });

            // Fallback to construction if not found in list (backward compatibility)
            if (!$path) {
                $path = "incidents/{$id}/{$filename}";
            }

            if (!Storage::exists($path)) {
                // Try with private/ prefix as a last resort for very old files
                $path = "private/" . $path;
                if (!Storage::exists($path)) {
                    abort(404, 'File not found on disk');
                }
            }

            $content = Storage::get($path);
            $mimeType = Storage::mimeType($path) ?? 'application/octet-stream';
            $size = Storage::size($path);

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];

            return response($content, 200, $headers);
        } catch (\Exception $e) {
            abort(500, 'Failed to view file: ' . $e->getMessage());
        }
    }

    /**
     * Download attachment
     */
    public function downloadAttachment(int $id, string $filename): \Illuminate\Http\Response
    {
        try {
            $incident = Incident::findOrFail($id);
            $evidencePaths = $incident->evidence_paths ?? [];

            // Find the full path that ends with this filename
            $path = collect($evidencePaths)->first(function ($p) use ($filename) {
                return str_ends_with($p, $filename);
            });

            // Fallback to construction if not found in list (backward compatibility)
            if (!$path) {
                $path = "incidents/{$id}/{$filename}";
            }

            if (!Storage::exists($path)) {
                // Try with private/ prefix as a last resort for very old files
                $path = "private/" . $path;
                if (!Storage::exists($path)) {
                    abort(404, 'File not found on disk');
                }
            }

            $content = Storage::get($path);
            $mimeType = Storage::mimeType($path) ?? 'application/octet-stream';
            $size = Storage::size($path);

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];

            return response($content, 200, $headers);
        } catch (\Exception $e) {
            abort(500, 'Failed to download file');
        }
    }

    /**
     * Delete an incident
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $incident = Incident::findOrFail($id);

            // Check permission - only admin can delete
            $user = auth()->user();
            if (!$user->hasRole(['Super Admin', 'Admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only administrators can delete incidents',
                ], 403);
            }

            $this->incidentService->deleteIncident($incident);

            return response()->json([
                'success' => true,
                'message' => 'Incident deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Incident not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete incident: ' . $e->getMessage(),
            ], 500);
        }
    }
}

