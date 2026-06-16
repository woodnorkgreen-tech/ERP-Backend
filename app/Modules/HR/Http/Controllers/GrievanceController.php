<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Grievance;
use App\Modules\HR\Services\GrievanceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GrievanceController extends Controller
{
    protected $grievanceService;

    private const GRIEVANCE_MANAGER_ROLES = ['Super Admin', 'Admin', 'HR Admin', 'HR'];

    public function __construct(GrievanceService $grievanceService)
    {
        $this->grievanceService = $grievanceService;
    }

    protected function canManageGrievances($user): bool
    {
        return $user && $user->hasAnyRole(self::GRIEVANCE_MANAGER_ROLES);
    }

    /**
     * Dept lead can access/manage a grievance only if:
     *  - they are actually a dept lead
     *  - they are NOT the accused party (against_id)
     *  - the complainant belongs to one of their accessible departments
     */
    protected function isDeptLeadForGrievance($user, Grievance $grievance): bool
    {
        if (!$user || !$user->isDeptLead()) return false;
        if ($grievance->against_id && $grievance->against_id === $user->id) return false;
        $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
        if (empty($accessibleDeptIds)) return false;
        $complainantDeptId = $grievance->complainant?->department_id;
        return $complainantDeptId && in_array($complainantDeptId, $accessibleDeptIds);
    }

    protected function canAccessGrievance($user, Grievance $grievance): bool
    {
        return $this->canManageGrievances($user)
            || ($user && $grievance->complainant_id === $user->id)
            || $this->isDeptLeadForGrievance($user, $grievance);
    }

    protected function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * Get all grievances with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $grievances = $this->grievanceService->getGrievances($request);

            return response()->json([
                'success' => true,
                'data' => $grievances->items(),
                'meta' => [
                    'current_page' => $grievances->currentPage(),
                    'last_page' => $grievances->lastPage(),
                    'per_page' => $grievances->perPage(),
                    'total' => $grievances->total(),
                    'from' => $grievances->firstItem(),
                    'to' => $grievances->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch grievances: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get grievance statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->grievanceService->getGrievanceStatistics();

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
     * Create a new grievance
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'against_id' => 'nullable|exists:users,id',
                'description' => 'required|string',
                'category' => [
                    'required',
                    'string',
                    Rule::in([
                        'Compensation & Benefits',
                        'Workplace Health & Safety',
                        'Bullying, Harassment & Discrimination',
                        'Performance & Disciplinary Actions',
                        'Work Assignments & Workloads',
                    ]),
                ],
                'witnesses' => 'nullable|string',
                'attachments' => 'nullable|array',
                'attachments.*' => 'file|max:2048|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $grievance = $this->grievanceService->createGrievance($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Grievance filed successfully',
                'data' => $grievance,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create grievance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single grievance
     */
    public function show(int $id): JsonResponse
    {
        try {
            $grievance = $this->grievanceService->getGrievanceById($id);
            $user = auth()->user();

            if (!$this->canAccessGrievance($user, $grievance)) {
                return $this->forbiddenResponse('You do not have permission to view this grievance');
            }

            return response()->json([
                'success' => true,
                'data' => $grievance,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grievance not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch grievance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a grievance
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canManageGrievances($user) && !$this->isDeptLeadForGrievance($user, $grievance)) {
                return $this->forbiddenResponse('You do not have permission to update this grievance');
            }

            $validator = Validator::make($request->all(), [
                'description' => 'sometimes|string',
                'category' => 'sometimes|string|in:Compensation & Benefits,Workplace Health & Safety,Bullying, Harassment & Discrimination,Performance & Disciplinary Actions,Work Assignments & Workloads',
                'status' => 'sometimes|in:Reported,Investigating,Resolved,Escalated,Closed',
                'witnesses' => 'nullable|string',
                'investigation_notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $grievance = $this->grievanceService->updateGrievance($grievance, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Grievance updated successfully',
                'data' => $grievance,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grievance not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update grievance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve a grievance
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canManageGrievances($user) && !$this->isDeptLeadForGrievance($user, $grievance)) {
                return $this->forbiddenResponse('You do not have permission to resolve grievances');
            }

            $validator = Validator::make($request->all(), [
                'resolution' => 'required|string',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $grievance = $this->grievanceService->resolveGrievance($grievance, $request->resolution, $request->notes);

            return response()->json([
                'success' => true,
                'message' => 'Grievance resolved successfully',
                'data' => $grievance,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grievance not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve grievance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Escalate a grievance
     */
    public function escalate(Request $request, int $id): JsonResponse
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canManageGrievances($user) && !$this->isDeptLeadForGrievance($user, $grievance)) {
                return $this->forbiddenResponse('You do not have permission to escalate grievances');
            }

            $validator = Validator::make($request->all(), [
                'to' => 'required|in:hr_officer,director',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $grievance = $this->grievanceService->escalateGrievance($grievance, $request->to);

            return response()->json([
                'success' => true,
                'message' => 'Grievance escalated successfully',
                'data' => $grievance,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grievance not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to escalate grievance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add comment to grievance
     */
    public function addComment(Request $request, int $id): JsonResponse
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canAccessGrievance($user, $grievance)) {
                return $this->forbiddenResponse('You do not have permission to comment on this grievance');
            }

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

            $comment = $this->grievanceService->addComment($grievance, $request->comment);

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'data' => $comment,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grievance not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add comment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload attachments for a grievance
     */
    public function uploadAttachments(Request $request, int $id): JsonResponse
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canAccessGrievance($user, $grievance)) {
                return $this->forbiddenResponse('You do not have permission to upload attachments for this grievance');
            }

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
            $storagePath = "grievances/{$id}";

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs($storagePath, $filename);
                    $uploadedPaths[] = $path;
                }
            }

            // Update grievance attachments
            $currentPaths = $grievance->attachments ?? [];
            $grievance->update([
                'attachments' => array_merge($currentPaths, $uploadedPaths)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attachments uploaded successfully',
                'data' => $uploadedPaths,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grievance not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload attachments: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View attachment inline.
     */
    public function viewAttachment(int $id, string $filename): \Illuminate\Http\Response
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canAccessGrievance($user, $grievance)) {
                abort(403, 'You do not have permission to view this attachment');
            }

            return $this->streamAttachment($grievance, $filename, 'inline');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, 'Grievance not found');
        } catch (\Exception $e) {
            abort(500, 'Failed to view file: ' . $e->getMessage());
        }
    }

    /**
     * Download attachment.
     */
    public function downloadAttachment(int $id, string $filename): \Illuminate\Http\Response
    {
        try {
            $grievance = Grievance::findOrFail($id);
            $user = auth()->user();

            if (!$this->canAccessGrievance($user, $grievance)) {
                abort(403, 'You do not have permission to download this attachment');
            }

            return $this->streamAttachment($grievance, $filename, 'attachment');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, 'Grievance not found');
        } catch (\Exception $e) {
            abort(500, 'Failed to download file');
        }
    }

    protected function streamAttachment(Grievance $grievance, string $filename, string $disposition): \Illuminate\Http\Response
    {
        $attachmentPaths = $grievance->attachments ?? [];

        $path = collect($attachmentPaths)->first(function ($p) use ($filename) {
            return str_ends_with($p, $filename);
        });

        if (!$path) {
            $path = "grievances/{$grievance->id}/{$filename}";
        }

        if (!Storage::exists($path)) {
            $fallbackPath = "private/" . $path;
            if (!Storage::exists($fallbackPath)) {
                abort(404, 'File not found on disk');
            }
            $path = $fallbackPath;
        }

        $content = Storage::get($path);
        $mimeType = Storage::mimeType($path) ?? 'application/octet-stream';
        $size = Storage::size($path);

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
