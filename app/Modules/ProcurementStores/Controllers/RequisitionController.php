<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Requisition;
use App\Http\Resources\RequisitionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class RequisitionController extends Controller
{
    public function index(Request $request)
    {
        $query = Requisition::with(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy']);

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
            } elseif ($dateFilter === 'custom' && $request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            }
        }

        // Status filtering
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Urgency filtering
        if ($request->has('urgency')) {
            $query->where('urgency', $request->urgency);
        }

        $requisitions = $query->orderBy('created_at', 'desc')->paginate(20);

        return RequisitionResource::collection($requisitions)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm');

        $requisitions = Requisition::with(['items.material', 'project', 'employee', 'department', 'createdBy'])
            ->where(function ($query) use ($searchTerm) {
                $query->where('requisition_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhereHas('project', function ($q) use ($searchTerm) {
                        $q->where('name', 'LIKE', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('employee', function ($q) use ($searchTerm) {
                        $q->where('name', 'LIKE', '%' . $searchTerm . '%');
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return RequisitionResource::collection($requisitions)->preserveQuery();
    }

public function store(Request $request)
{
    $input = $request->all();
    
    $validator = Validator::make($input, [
        'date' => 'required|date',
        'requested_by_type' => 'required|in:project,office,employee',
        'project_id' => 'required_if:requested_by_type,project',
        'employee_id' => 'required_if:requested_by_type,employee',
        'department_id' => 'required_if:requested_by_type,office',
        'urgency' => 'required|in:normal,urgent',
        'items' => 'required|array|min:1',
        'items.*.material_id' => 'required|exists:library_materials,id',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.purpose' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response(['error' => $validator->errors()], 422);
    }

    try {
        DB::beginTransaction();

        $input['requisition_number'] = Requisition::generateRequisitionNumber();
        $input['user_id'] = auth()->id();

        // **IMPORTANT: Clear unused fields based on requested_by_type**
        if ($input['requested_by_type'] === 'project') {
            $input['employee_id'] = null;
            $input['department_id'] = null;
        } elseif ($input['requested_by_type'] === 'employee') {
            $input['project_id'] = null;
            $input['department_id'] = null;
        } elseif ($input['requested_by_type'] === 'office') {
            $input['project_id'] = null;
            $input['employee_id'] = null;
        }

        $items = $input['items'];
        unset($input['items']);

        $requisition = Requisition::create($input);

        foreach ($items as $item) {
            $requisition->items()->create($item);
        }

        DB::commit();

        return new RequisitionResource($requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy']));
    } catch (\Exception $e) {
        DB::rollBack();
        return response(['error' => 'Failed to create requisition: ' . $e->getMessage()], 500);
    }
}

public function update(Request $request, Requisition $requisition)
{
    $input = $request->all();
    
    $validator = Validator::make($input, [
        'date' => 'date',
        'requested_by_type' => 'in:project,office,employee',
        'urgency' => 'in:normal,urgent',
        'status' => 'in:pending,approved,rejected,completed',
    ]);

    if ($validator->fails()) {
        return response(['error' => $validator->errors()], 422);
    }

    try {
        DB::beginTransaction();

        // **IMPORTANT: Clear unused fields based on requested_by_type**
        if (isset($input['requested_by_type'])) {
            if ($input['requested_by_type'] === 'project') {
                $input['employee_id'] = null;
                $input['department_id'] = null;
            } elseif ($input['requested_by_type'] === 'employee') {
                $input['project_id'] = null;
                $input['department_id'] = null;
            } elseif ($input['requested_by_type'] === 'office') {
                $input['project_id'] = null;
                $input['employee_id'] = null;
            }
        }

        if (isset($input['items'])) {
            $items = $input['items'];
            unset($input['items']);

            $requisition->items()->delete();
            
            foreach ($items as $item) {
                $requisition->items()->create($item);
            }
        }

        $requisition->update($input);

        DB::commit();

        return new RequisitionResource($requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy']));
    } catch (\Exception $e) {
        DB::rollBack();
        return response(['error' => 'Failed to update requisition: ' . $e->getMessage()], 500);
    }
}
    public function show(Requisition $requisition)
    {
        return new RequisitionResource($requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy']));
    }

   
    public function destroy(Requisition $requisition)
    {
        $requisition->delete();
        
        return response(['message' => 'Requisition deleted successfully']);
    }

    public function submitForApproval(Requisition $requisition)
    {
        if ($requisition->status !== 'draft') {
            return response(['error' => 'Only draft requisitions can be submitted'], 422);
        }

        $requisition->submitForApproval();

        return new RequisitionResource($requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy']));
    }

    public function approve(Requisition $requisition)
    {
        if ($requisition->status !== 'pending_approval') {
            return response(['error' => 'Only pending requisitions can be approved'], 422);
        }

        $requisition->approve(auth()->id());

        return new RequisitionResource($requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy']));
    }

    public function reject(Request $request, Requisition $requisition)
    {
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

        return new RequisitionResource($requisition->load(['items.material', 'project', 'employee', 'department', 'createdBy', 'approvedBy']));
    }
}