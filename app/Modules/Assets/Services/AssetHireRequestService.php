<?php

namespace App\Modules\Assets\Services;

use App\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignmentHistory;
use App\Modules\Assets\Models\AssetHireRequest;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

class AssetHireRequestService
{
    /**
     * Only a department lead, Admin, HR, or Super Admin may directly
     * assign a long-term company asset (e.g. a laptop) — they're the ones
     * who know what's available to give out.
     */
    public function canCreateAssignType(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'HR']) || $user->is_dept_lead;
    }

    /**
     * Who can act on (approve/reject) a request: the target person's
     * department lead, their direct manager, HR, Admin, or Super Admin.
     */
    public function canApprove(User $approver, AssetHireRequest $request): bool
    {
        if ($approver->hasRole(['Super Admin', 'Admin', 'HR'])) {
            return true;
        }

        if (!$approver->employee_id) {
            return false;
        }

        $forUser = $request->forUser ?? $request->load('forUser')->forUser;
        if (!$forUser) {
            return false;
        }

        if ($forUser->department_id) {
            $isDeptLead = Department::where('id', $forUser->department_id)
                ->where('manager_id', $approver->employee_id)
                ->exists();
            if ($isDeptLead) {
                return true;
            }
        }

        if ($forUser->employee_id) {
            $isDirectManager = Employee::where('id', $forUser->employee_id)
                ->where('manager_id', $approver->employee_id)
                ->exists();
            if ($isDirectManager) {
                return true;
            }
        }

        return false;
    }

    /**
     * Department ids this user leads — used to widen their visibility to
     * pending requests waiting on their approval, even before they act on them.
     */
    public function managedDepartmentIds(User $user): array
    {
        if (!$user->employee_id) {
            return [];
        }

        return Department::where('manager_id', $user->employee_id)->pluck('id')->toArray();
    }

    /**
     * Broader visibility check for an asset's full hire history / movement
     * log — wider than canApprove(), since people who never had to act on
     * a specific request may still legitimately need the full picture:
     * Admin/HR/Super Admin, any department lead, and anyone who's ever
     * appeared on any request for this asset (requested it, held it,
     * approved/rejected/returned it).
     */
    public function canViewAssetHistory(User $user, int $assetId): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin', 'HR'])) {
            return true;
        }

        if ($user->is_dept_lead) {
            return true;
        }

        return AssetHireRequest::where('asset_id', $assetId)
            ->where(function ($q) use ($user) {
                $q->where('requested_by', $user->id)
                    ->orWhere('for_user_id', $user->id)
                    ->orWhere('approved_by', $user->id)
                    ->orWhere('rejected_by', $user->id)
                    ->orWhere('returned_by', $user->id);
            })
            ->exists();
    }

    public function create(array $data, User $requester): AssetHireRequest
    {
        $asset = Asset::findOrFail($data['asset_id']);

        if (!$asset->is_available) {
            throw new \Exception('This asset is not currently available.');
        }

        return AssetHireRequest::create([
            'asset_id' => $asset->id,
            'request_type' => $data['request_type'],
            'project_id' => $data['project_id'] ?? null,
            'requested_by' => $requester->id,
            'for_user_id' => $data['for_user_id'],
            'status' => AssetHireRequest::STATUS_PENDING,
            'out_date' => $data['out_date'],
            'expected_return_date' => $data['expected_return_date'] ?? null,
            'purpose' => $data['purpose'] ?? null,
        ]);
    }

    /**
     * Approve a request — hands the asset over: marks it unavailable,
     * closes out whoever held it before, and opens a fresh assignment
     * history entry for the new holder.
     */
    public function approve(AssetHireRequest $request, User $approver): AssetHireRequest
    {
        if ($request->status !== AssetHireRequest::STATUS_PENDING) {
            throw new \Exception('Only pending requests can be approved.');
        }

        DB::transaction(function () use ($request, $approver) {
            $request->update([
                'status' => AssetHireRequest::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $request->asset->update([
                'is_available' => false,
                'updated_by' => $approver->id,
            ]);

            // Close out whoever held it before (shouldn't normally happen
            // since the asset must be available to request, but covers
            // the edge case safely instead of leaving two open entries).
            AssetAssignmentHistory::where('asset_id', $request->asset_id)
                ->active()
                ->update(['ended_at' => $request->out_date ?? now()->toDateString()]);

            AssetAssignmentHistory::create([
                'asset_id' => $request->asset_id,
                'hire_request_id' => $request->id,
                'held_by' => $request->for_user_id,
                'assigned_by' => $approver->id,
                'started_at' => $request->out_date ?? now()->toDateString(),
            ]);
        });

        return $request->fresh();
    }

    public function reject(AssetHireRequest $request, User $rejecter, ?string $reason): AssetHireRequest
    {
        if ($request->status !== AssetHireRequest::STATUS_PENDING) {
            throw new \Exception('Only pending requests can be rejected.');
        }

        $request->update([
            'status' => AssetHireRequest::STATUS_REJECTED,
            'rejected_by' => $rejecter->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->fresh();
    }

    /**
     * Let the requester pull back their own request before anyone's acted on it.
     */
    public function cancel(AssetHireRequest $request, User $user): AssetHireRequest
    {
        if ($request->status !== AssetHireRequest::STATUS_PENDING) {
            throw new \Exception('Only pending requests can be cancelled.');
        }

        if ($request->requested_by !== $user->id && !$user->hasRole(['Super Admin', 'Admin'])) {
            throw new \Exception('Only the requester can cancel this request.');
        }

        $request->update(['status' => AssetHireRequest::STATUS_CANCELLED]);

        return $request->fresh();
    }

    /**
     * Closes the loop: frees the asset back up, records the condition it
     * came back in, and closes the holder's entry in the assignment history.
     * Used for both hire returns and ending a long-term assignment.
     */
    public function markReturned(AssetHireRequest $request, User $returner, array $data): AssetHireRequest
    {
        if ($request->status !== AssetHireRequest::STATUS_APPROVED) {
            throw new \Exception('Only approved (currently checked-out) requests can be marked returned.');
        }

        DB::transaction(function () use ($request, $returner, $data) {
            $returnDate = $data['actual_return_date'] ?? now()->toDateString();

            $request->update([
                'status' => AssetHireRequest::STATUS_RETURNED,
                'actual_return_date' => $returnDate,
                'return_condition' => $data['return_condition'] ?? null,
                'returned_by' => $returner->id,
            ]);

            $request->asset->update([
                'is_available' => true,
                'condition' => $data['return_condition'] ?? $request->asset->condition,
                'updated_by' => $returner->id,
            ]);

            AssetAssignmentHistory::where('hire_request_id', $request->id)
                ->active()
                ->update(['ended_at' => $returnDate]);
        });

        return $request->fresh();
    }
}
