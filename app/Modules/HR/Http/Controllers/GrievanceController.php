<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Grievance;
use App\Modules\HR\Services\GrievanceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class GrievanceController extends Controller
{
    protected $grievanceService;

    public function __construct(GrievanceService $grievanceService)
    {
        $this->grievanceService = $grievanceService;
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
            $validator = Validator::make($request->all(), [
                'complainant_id' => 'nullable|exists:users,id',
                'against_id' => 'nullable|exists:users,id',
                'description' => 'required|string',
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

            // Check permissions - only HR and Super Admin can view
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this grievance',
                ], 403);
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

            // Check permissions - only HR and Super Admin can update
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to update this grievance',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'description' => 'sometimes|string',
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

            // Check permission
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to resolve grievances',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'resolution' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $grievance = $this->grievanceService->resolveGrievance($grievance, $request->resolution);

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

            // Check permission
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to escalate grievances',
                ], 403);
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
}