<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Requisition;
use App\Http\Resources\RequisitionResource;
use App\Services\RequisitionNotificationService;
use App\Services\ProcurementOperationalSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

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

    private function syncProjectProcurement(Requisition|int $requisition): void
    {
        try {
            app(ProcurementOperationalSyncService::class)->syncRequisition($requisition);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement from requisition', [
                'requisition' => $requisition instanceof Requisition ? $requisition->id : $requisition,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function syncProjectProcurementTasks(iterable $taskIds): void
    {
        $sync = app(ProcurementOperationalSyncService::class);

        foreach ($taskIds as $taskId) {
            $sync->safeSyncTask((int) $taskId);
        }
    }

    /**
     * Check if user has permission to view all requisitions
     * Super Admin, Admin, Accounts, Procurement, and Stores can see all
     */
    private function canSeeAll()
    {
        $user = auth()->user();

        if (!$user || !$user->roles) {
            return false;
        }

        $allowedRoles = ['Super Admin', 'Admin', 'Accounts', 'Procurement', 'Stores', 'Manager'];
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
            'items.material', 'items.supplier',
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

        if ($request->has('requested_by_type') && $request->requested_by_type !== '') {
            $query->where('requested_by_type', $request->requested_by_type);
        }

        // Filter by user if they don't have permission to see all
        if (!$this->canSeeAll()) {
            $query->where('user_id', auth()->id());
        }

        $requisitions = $query->orderBy('created_at', 'desc')->paginate(20);

        return RequisitionResource::collection($requisitions)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm', '');

        $query = Requisition::with([
            'items.material', 'items.supplier',
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

        // Filter by user if they don't have permission to see all
        if (!$this->canSeeAll()) {
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
            'items.*.project_enquiry_id' => 'nullable|integer',
            'items.*.procurement_task_id' => 'nullable|integer',
            'items.*.budget_data_id'     => 'nullable|integer',
            'items.*.budget_element_id'  => 'nullable|string',
            'items.*.budget_element_persistent_id' => 'nullable|string',
            'items.*.budget_item_id'     => 'nullable|string',
            'items.*.budget_item_persistent_id' => 'nullable|string',
            'items.*.material_id'        => 'nullable|exists:library_materials,id',
            'items.*.expense_code_id'    => 'required_if:requested_by_type,project|integer|exists:expense_codes,id',
            // Either material_id must be present OR custom_description must be provided
            'items.*.custom_description' => 'nullable|string',
            'items.*.quantity'           => 'required|numeric|gt:0',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.internal_budget_unit_price' => 'nullable|numeric|min:0',
            'items.*.purpose'            => 'required|string',
            'items.*.procurement_item_snapshot' => 'nullable|array',
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
                $item['uom_id'] = $this->buyingUomId($item['material_id'] ?? null);
                $item['total'] = $item['quantity'] * $item['unit_price'];
                $item['custom_description'] = $item['custom_description'] ?? null;
                $item['project_enquiry_id'] = $item['project_enquiry_id'] ?? (
                    $input['requested_by_type'] === 'project' ? $input['project_id'] : null
                );
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

            $this->syncProjectProcurement($requisition);

            return new RequisitionResource(
                $requisition->load(['items.material', 'items.supplier', 'project', 'projectEnquiry', 'employee', 'department', 'createdBy'])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to create requisition: ' . $e->getMessage()], 500);
        }
    }

    public function show(Requisition $requisition)
    {
        return new RequisitionResource(
            $requisition->load(['items.material', 'items.supplier', 'project', 'projectEnquiry', 'employee', 'department', 'createdBy'])
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
            'items'              => 'sometimes|array|min:1',
            'items.*.project_enquiry_id' => 'nullable|integer',
            'items.*.procurement_task_id' => 'nullable|integer',
            'items.*.budget_data_id' => 'nullable|integer',
            'items.*.budget_element_id' => 'nullable|string',
            'items.*.budget_element_persistent_id' => 'nullable|string',
            'items.*.budget_item_id' => 'nullable|string',
            'items.*.budget_item_persistent_id' => 'nullable|string',
            'items.*.material_id' => 'nullable|exists:library_materials,id',
            'items.*.expense_code_id' => 'nullable|integer|exists:expense_codes,id',
            'items.*.supplier_id' => 'nullable|exists:suppliers,id',
            'items.*.custom_description' => 'nullable|string',
            'items.*.quantity' => 'required_with:items|numeric|gt:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.internal_budget_unit_price' => 'nullable|numeric|min:0',
            // Project-sourced items don't need a manually typed purpose — the
            // budget/element info already says why it's needed. Only office
            // and employee requisitions require it.
            'items.*.purpose' => 'nullable|string',
            'items.*.procurement_item_snapshot' => 'nullable|array',
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
                    $item['uom_id'] = $this->buyingUomId($item['material_id'] ?? null);
                    $item['total'] = $item['quantity'] * $item['unit_price'];
                    $totalAmount  += $item['total'];
                    $item['project_enquiry_id'] = $item['project_enquiry_id'] ?? (
                        ($input['requested_by_type'] ?? $requisition->requested_by_type) === 'project'
                            ? ($input['project_id'] ?? $requisition->project_id)
                            : null
                    );
                    // purpose is required in the DB — if it wasn't provided
                    // (normal for project-sourced items, which don't show a
                    // purpose field), derive one from the budget snapshot so
                    // it's still meaningful, instead of failing to save.
                    if (empty($item['purpose'])) {
                        $snapshot = $item['procurement_item_snapshot'] ?? [];
                        $item['purpose'] = trim(
                            ($snapshot['elementName'] ?? '') . ' – ' .
                            ($snapshot['description'] ?? $item['custom_description'] ?? 'Project material'),
                            ' –'
                        ) ?: 'Project material requisition';
                    }
                    $requisition->items()->create($item);
                }

                $input['total_amount'] = $totalAmount;
            }

            $requisition->update($input);

            DB::commit();

            $this->syncProjectProcurement($requisition);

            return new RequisitionResource(
                $requisition->load(['items.material', 'items.supplier', 'project', 'projectEnquiry', 'employee', 'department', 'createdBy', 'approvedBy'])
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

        $taskIds = app(ProcurementOperationalSyncService::class)->taskIdsForRequisition($requisition);
        $requisition->delete();
        $this->syncProjectProcurementTasks($taskIds);

        return response(['message' => 'Requisition deleted successfully']);
    }

    public function submitForApproval(Requisition $requisition)
    {
        if ($requisition->status !== 'draft') {
            return response(['error' => 'Only draft requisitions can be submitted'], 422);
        }

        $requisition->submitForApproval();
        $this->syncProjectProcurement($requisition);

        return new RequisitionResource(
            $requisition->load(['items.material', 'items.supplier', 'project', 'employee', 'department', 'createdBy', 'approvedBy'])
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
        $this->syncProjectProcurement($requisition);

        return new RequisitionResource(
            $requisition->load(['items.material', 'items.supplier', 'project', 'employee', 'department', 'createdBy', 'approvedBy'])
        );
    }

    private function buyingUomId(mixed $materialId): ?int
    {
        if (! $materialId) return null;
        $material = LibraryMaterial::findOrFail((int) $materialId);
        return $material->purchase_uom_id ?: $material->base_uom_id;
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
        $this->syncProjectProcurement($requisition);

        return new RequisitionResource(
            $requisition->load(['items.material', 'items.supplier', 'project', 'employee', 'department', 'createdBy', 'approvedBy'])
        );
    }
}