<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\ProfileUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileUpdateApprovalController extends Controller
{
    /**
     * Get a paginated list of pending profile update requests.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        
        $requests = ProfileUpdateRequest::with('employee:id,employee_id,first_name,last_name,department_id,position')
            ->where('status', $status)
            ->orderBy('created_at', 'asc')
            ->paginate(20);

        return response()->json($requests);
    }

    /**
     * Approve a profile update request.
     */
    public function approve($id): JsonResponse
    {
        $updateRequest = ProfileUpdateRequest::with('employee')->findOrFail($id);

        if ($updateRequest->status !== 'pending') {
            return response()->json(['message' => 'Request is already ' . $updateRequest->status], 422);
        }

        return DB::transaction(function () use ($updateRequest) {
            $updateRequest->employee->update($updateRequest->requested_data);

            $updateRequest->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Profile update approved successfully',
                'data' => $updateRequest
            ]);
        });
    }

    /**
     * Reject a profile update request.
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $updateRequest = ProfileUpdateRequest::findOrFail($id);

        if ($updateRequest->status !== 'pending') {
            return response()->json(['message' => 'Request is already ' . $updateRequest->status], 422);
        }

        $updateRequest->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Profile update rejected successfully',
            'data' => $updateRequest
        ]);
    }
}
