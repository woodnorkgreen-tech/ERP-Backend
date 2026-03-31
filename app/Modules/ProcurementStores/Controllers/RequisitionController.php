<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Requisition;
use App\Http\Resources\RequisitionResource;
use App\Services\RequisitionNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class RequisitionController extends Controller
{
    /**
     * Check if user can view all requisitions
     */
    private function canViewAll()
    {
        $user = auth()->user();

        if (!$user || !$user->roles) {
            return false;
        }

        $allowedRoles = ['Super Admin', 'Admin', 'Accounts', 'Stores', 'Procurement', 'Manager'];
        $userRoles = $user->roles->pluck('name')->toArray();

        foreach ($allowedRoles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has approval/delete permissions
     * Only Super Admin, Admin, and Accounts roles can approve/reject/delete
     */
    private function canApproveOrDelete()
    {
        $user = auth()->user();

        if (!$user || !$user->roles) {
            return false;
        }

        $allowedRoles = ['Super Admin', 'Admin', 'Accounts'];
        $userRoles = $user->roles->pluck('name')->toArray();

        foreach ($allowedRoles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the "requested by" label for notifications
     */
    private function getRequestedByLabel(Requisition $requisition, string $type): string
    {
        return match($type) {
            'project'  => $requisition->project?->project_name ?? 'Project',
            'employee' => trim(
                ($requisition->employee?->first_name ?? '') . ' ' .
                ($requisition->employee?->last_name ?? '')
            ),
            'office'   => $requisition->department?->name ?? 'Department',
            default    => 'Unknown',
        };
    }

    public function index(Request $request)
    {
        $query = Requisition::with([
            'items.material',
            'project.enquiry',
            'project',
            'projectEnquiry',
            'employee',
            'department',
            'createdBy',
            'approvedBy'
        ]);

        // Date filtering
        if ($request->has('date_filter')) {
            $dateFilter = $request->input('date_filter');

            if ($dateFilter === 'today') {
                $query->whereDate('date', today());
            } elseif ($dateFilter === 'past_7_days') {
                $query->whereDate('date', '>=', now()->subDays(7));
            } elseif ($dateFilter === 'past_30_days') {
                $query->whereDate('date', '>=', now()->subDays(30));
            } elseif ($dateFilter === 'this_month') {
                $query->whereMonth('date', now()->month)
                    ->whereYear('date', now()->year);
            } elseif (
                $dateFilter === 'custom' &&
                $request->has('start_date') &&
                $request->has('end_date')
            ) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('urgency')) {
            $query->where('urgency', $request->urgency);
        }

        // Filter by user if they are not an authorized viewer
        if (!$this->canViewAll()) {
            $query->where('user_id', auth()->id());
        }

        $requisitions = $query->orderBy('created_at', 'desc')->paginate(20);

        return RequisitionResource::collection($requisitions)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');

        $query = Requisition::with([
            'items.material',
            'project.enquiry',
            'project',
            'projectEnquiry',
            'employee',
            'department',
            'createdBy',
            'approvedBy'
        ]);

        if (!empty($searchTerm)) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('requisition_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhereHas('project', function ($projectQuery) use ($searchTerm) {
                        $projectQuery->where('project_name', 'LIKE', '%' . $searchTerm . '%')
                            ->orWhere('project_code', 'LIKE', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('employee', function ($employeeQuery) use ($searchTerm) {
                        $employeeQuery->where('first_name', 'LIKE', '%' . $searchTerm . '%')
                            ->orWhere('last_name', 'LIKE', '%' . $searchTerm . '%')
                            ->orWhere('employee_number', 'LIKE', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('department', function ($deptQuery) use ($searchTerm) {
                        $deptQuery->where('department_name', 'LIKE', '%' . $searchTerm . '%');
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('urgency')) {
            $query->where('urgency', $request->urgency);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        // Filter by user if they are not an authorized viewer
        if (!$this->canViewAll()) {
            $query->where('user_id', auth()->id());
        }

        $requisitions = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('perPage', 20));

        return RequisitionResource::collection($requisitions)->preserveQuery();
    }

    public function store(Request $request)
    {
        $input = $request->all();

        // Debug log to capture payload for troubleshooting
        \Log::info('[Requisition Store] Incoming payload', [
            'requested_by_type' => $input['requested_by_type'] ?? null,
            'project_id'        => $input['project_id'] ?? null,
            'items_count'       => count($input['items'] ?? []),
            'first_item'        => $input['items'][0] ?? null,
        ]);

        $validator = Validator::make($input, [
            'date'                       => 'required|date',
            'requested_by_type'          => 'required|in:project,office,employee',
            'project_id'                 => 'required_if:requested_by_type,project',
            'employee_id'                => 'required_if:requested_by_type,employee',
            'department_id'              => 'required_if:requested_by_type,office',
            'urgency'                    => 'required|in:normal,urgent',
            'job_number'                 => 'nullable|string',
            'items'                      => 'required|array|min:1',
            'items.*.material_id'        => 'nullable|exists:library_materials,id',
            // Either material_id must be present OR custom_description must be provided
            'items.*.custom_description' => 'nullable|string',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.purpose'            => 'required|string',
        ]);

        if ($validator->fails()) {
            \Log::warning('[Requisition Store] Validation failed', $validator->errors()->toArray());
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $input['requisition_number'] = Requisition::generateRequisitionNumber();
            $input['user_id'] = auth()->id();

            if ($input['requested_by_type'] === 'project') {
                $input['employee_id']   = null;
                $input['department_id'] = null;
            } elseif ($input['requested_by_type'] === 'employee') {
                $input['project_id']    = null;
                $input['department_id'] = null;
            } elseif ($input['requested_by_type'] === 'office') {
                $input['project_id']  = null;
                $input['employee_id'] = null;
            }

            // Calculate total
            $totalAmount = 0;
            foreach ($input['items'] as $item) {
                $totalAmount += $item['quantity'] * $item['unit_price'];
            }
            $input['total_amount'] = $totalAmount;

            $items = $input['items'];
            unset($input['items']);

            $requisition = Requisition::create($input);

            foreach ($items as $item) {
                $item['total'] = $item['quantity'] * $item['unit_price'];
                $item['custom_description'] = $item['custom_description'] ?? null;
                $requisition->items()->create($item);
            }

            DB::commit();

            // Send push notification to approvers
            try {
                $requestedBy = $this->getRequestedByLabel(
                    $requisition->load(['project', 'employee', 'department']),
                    $input['requested_by_type']
                );

                RequisitionNotificationService::notifyApprovers(
                    $requisition->requisition_number,
                    $requestedBy,
                    $input['urgency'] ?? 'normal'
                );
            } catch (\Exception $e) {
                \Log::error('Requisition notification failed: ' . $e->getMessage());
            }

            return new RequisitionResource(
                $requisition->load(['items.material', 'project', 'projectEnquiry', 'employee', 'department', 'createdBy'])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to create requisition: ' . $e->getMessage()], 500);
        }
    }

    public function show(Requisition $requisition)
    {
        return new RequisitionResource(
            $requisition->load(['items.material', 'project', 'projectEnquiry', 'employee', 'department', 'createdBy'])
        );
    }

    public function update(Request $request, Requisition $requisition)
    {
        if ($requisition->purchaseOrder) {
            return response([
                'error' => 'Cannot edit requisition after it has been linked to a purchase order'
            ], 403);
        }

        $input = $request->all();

        $validator = Validator::make($input, [
            'date'               => 'date',
            'requested_by_type'  => 'in:project,office,employee',
            'urgency'            => 'in:normal,urgent',
            'status'             => 'in:pending,approved,rejected,completed',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            if (isset($input['requested_by_type'])) {
                if ($input['requested_by_type'] === 'project') {
                    $input['employee_id']   = null;
                    $input['department_id'] = null;
                } elseif ($input['requested_by_type'] === 'employee') {
                    $input['project_id']    = null;
                    $input['department_id'] = null;
                } elseif ($input['requested_by_type'] === 'office') {
                    $input['project_id']  = null;
                    $input['employee_id'] = null;
                }
            }

            if (isset($input['items'])) {
                $items = $input['items'];
                unset($input['items']);

                $requisition->items()->delete();

                $totalAmount = 0;
                foreach ($items as $item) {
                    $item['total'] = $item['quantity'] * $item['unit_price'];
                    $totalAmount  += $item['total'];
                    $requisition->items()->create($item);
                }

                $input['total_amount'] = $totalAmount;
            }

            $requisition->update($input);

            DB::commit();

            return new RequisitionResource(
                $requisition->load(['items.material', 'project', 'projectEnquiry', 'employee', 'department', 'createdBy', 'approvedBy'])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to update requisition: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Requisition $requisition)
    {
        if (!$this->canApproveOrDelete()) {
            return response([
                'error' => 'Unauthorized. Only Super Admin, Admin, and Accounts can delete requisitions.'
            ], 403);
        }

        if ($requisition->purchaseOrder) {
            return response([
                'error' => 'Cannot delete requisition after it has been linked to a purchase order'
            ], 403);
        }

        $requisition->delete();
        return response(['message' => 'Requisition deleted successfully']);
    }

    public function submitForApproval(Requisition $requisition)
    {
        if ($requisition->status !== 'draft') {
            return response(['error' => 'Only draft requisitions can be submitted'], 422);
        }

        $requisition->submitForApproval();

        return new RequisitionResource(
            $requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy'])
        );
    }

    public function approve(Requisition $requisition)
    {
        if (!$this->canApproveOrDelete()) {
            return response([
                'error' => 'Unauthorized. Only Super Admin, Admin, and Accounts can approve requisitions.'
            ], 403);
        }

        if ($requisition->status !== 'pending_approval') {
            return response(['error' => 'Only pending requisitions can be approved'], 422);
        }

        $requisition->approve(auth()->id());

        return new RequisitionResource(
            $requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy'])
        );
    }

    public function reject(Request $request, Requisition $requisition)
    {
        if (!$this->canApproveOrDelete()) {
            return response([
                'error' => 'Unauthorized. Only Super Admin, Admin, and Accounts can reject requisitions.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        if ($requisition->status !== 'pending_approval') {
            return response(['error' => 'Only pending requisitions can be rejected'], 422);
        }

        $requisition->reject(auth()->id(), $request->reason);

        return new RequisitionResource(
            $requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy'])
        );
    }
}
