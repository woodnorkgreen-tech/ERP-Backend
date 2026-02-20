<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\ProductionDefectCode;
use App\Modules\Production\Models\ProductionNcr;
use App\Modules\Production\Models\ProductionNcrAssignment;
use App\Modules\Production\Models\ProductionNcrClosure;
use App\Modules\Production\Models\ProductionNcrEvent;
use App\Modules\Production\Models\ProductionRootCauseCode;
use App\Modules\Production\Services\ProductionNcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductionNcrController extends Controller
{
    public function __construct(private readonly ProductionNcrService $ncrService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ProductionNcr::query()
            ->with([
                'workOrder:id,work_order_number,title,project_enquiry_id',
                'defectCode:id,code,name',
                'rootCauseCode:id,code,name',
                'owner:id,name',
                'detector:id,name',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('work_order_id')) {
            $query->where('work_order_id', $request->integer('work_order_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('ncr_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('source_ref', 'like', "%{$search}%");
            });
        }

        if ($request->filled('shift')) {
            $query->where('shift', $request->string('shift')->toString());
        }

        $items = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    public function show(ProductionNcr $ncr): JsonResponse
    {
        $ncr->load([
            'workOrder:id,work_order_number,title,project_enquiry_id',
            'rework:id,work_order_id,title,status,qc_status,target_date',
            'defectCode:id,code,name',
            'rootCauseCode:id,code,name',
            'owner:id,name',
            'detector:id,name',
            'creator:id,name',
            'closer:id,name',
            'events.performer:id,name',
            'assignments.assignee:id,name',
            'assignments.assigner:id,name',
            'closure.verifier:id,name',
            'closure.creator:id,name',
        ]);

        if ($ncr->image_path) {
            $ncr->image_url = Storage::disk('public')->url($ncr->image_path);
        }

        return response()->json([
            'success' => true,
            'data' => $ncr
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_order_id' => 'nullable|exists:work_orders,id|required_without:job_order_no',
            'work_order_rework_id' => 'nullable|exists:work_order_reworks,id',
            'defect_code_id' => 'nullable|exists:production_defect_codes,id',
            'root_cause_code_id' => 'nullable|exists:production_root_cause_codes,id',
            'source_type' => 'nullable|in:mid_qc,final_qc,rework_qc,manual,other',
            'source_ref' => 'nullable|string|max:160',
            'timestamp' => 'nullable|date',
            'shift' => 'nullable|string|max:50',
            'raised_by_name' => 'nullable|string|max:160',
            'job_order_no' => 'nullable|string|max:120|required_without:work_order_id',
            'qc_stage' => 'nullable|string|max:50',
            'workstation' => 'nullable|string|max:120',
            'severity' => 'required|in:minor,major,critical',
            'description' => 'required|string',
            'quantity_affected' => 'nullable|numeric|min:0',
            'failure_description' => 'nullable|string',
            'primary_sop_breached' => 'nullable|string|max:200',
            'conformance_type' => 'nullable|string|max:80',
            'items_rejected' => 'nullable|numeric|min:0',
            'items_rejected_status' => 'nullable|in:yes,no,delayed,na',
            'rejects_location' => 'nullable|string|max:160',
            'production_impact' => 'nullable|string',
            'client_impacted' => 'nullable|boolean',
            'immediate_action_taken' => 'nullable|string',
            'root_cause_category' => 'nullable|string|max:120',
            'root_cause_description' => 'nullable|string',
            'preventive_action' => 'nullable|string',
            'reinspection_performed' => 'nullable|boolean',
            'reinspection_performed_status' => 'nullable|in:yes,no,na,other',
            'reinspection_performed_other' => 'nullable|string|max:255|required_if:reinspection_performed_status,other',
            'reinspection_results' => 'nullable|string',
            'resolution' => 'nullable|string',
            'supervisor_approval' => 'nullable|boolean',
            'containment_action' => 'nullable|string',
            'corrective_action' => 'nullable|string',
            'due_date' => 'nullable|date',
            'owner_user_id' => 'nullable|exists:users,id',
            'is_concession_approved' => 'nullable|boolean',
            'concession_reason' => 'nullable|string'
        ]);

        $ncr = null;
        DB::transaction(function () use (&$ncr, $validated) {
            $reinspectionStatus = $validated['reinspection_performed_status'] ?? null;
            $reinspectionPerformed = array_key_exists('reinspection_performed', $validated)
                ? (bool) $validated['reinspection_performed']
                : ($reinspectionStatus === 'yes');
            if (! $reinspectionStatus && array_key_exists('reinspection_performed', $validated)) {
                $reinspectionStatus = $reinspectionPerformed ? 'yes' : 'no';
            }

            $ncr = ProductionNcr::create([
                'ncr_number' => $this->ncrService->generateNcrNumber(),
                'work_order_id' => $validated['work_order_id'] ?? null,
                'work_order_rework_id' => $validated['work_order_rework_id'] ?? null,
                'defect_code_id' => $validated['defect_code_id'] ?? null,
                'root_cause_code_id' => $validated['root_cause_code_id'] ?? null,
                'source_type' => $validated['source_type'] ?? 'manual',
                'source_ref' => $validated['source_ref'] ?? null,
                'raised_by_name' => $validated['raised_by_name'] ?? null,
                'shift' => $validated['shift'] ?? null,
                'job_order_no' => $validated['job_order_no'] ?? null,
                'qc_stage' => $validated['qc_stage'] ?? null,
                'workstation' => $validated['workstation'] ?? null,
                'severity' => $validated['severity'],
                'status' => 'open',
                'description' => $validated['description'],
                'quantity_affected' => $validated['quantity_affected'] ?? null,
                'failure_description' => $validated['failure_description'] ?? null,
                'primary_sop_breached' => $validated['primary_sop_breached'] ?? null,
                'conformance_type' => $validated['conformance_type'] ?? null,
                'items_rejected' => $validated['items_rejected'] ?? null,
                'items_rejected_status' => $validated['items_rejected_status'] ?? null,
                'rejects_location' => $validated['rejects_location'] ?? null,
                'production_impact' => $validated['production_impact'] ?? null,
                'client_impacted' => $validated['client_impacted'] ?? false,
                'immediate_action_taken' => $validated['immediate_action_taken'] ?? null,
                'root_cause_category' => $validated['root_cause_category'] ?? null,
                'root_cause_description' => $validated['root_cause_description'] ?? null,
                'preventive_action' => $validated['preventive_action'] ?? null,
                'reinspection_performed' => $reinspectionPerformed,
                'reinspection_performed_status' => $reinspectionStatus,
                'reinspection_performed_other' => ($reinspectionStatus === 'other') ? ($validated['reinspection_performed_other'] ?? null) : null,
                'reinspection_results' => $validated['reinspection_results'] ?? null,
                'resolution' => $validated['resolution'] ?? null,
                'supervisor_approval' => $validated['supervisor_approval'] ?? false,
                'supervisor_approved_by' => ($validated['supervisor_approval'] ?? false) ? auth()->id() : null,
                'supervisor_approved_at' => ($validated['supervisor_approval'] ?? false) ? now() : null,
                'containment_action' => $validated['containment_action'] ?? null,
                'corrective_action' => $validated['corrective_action'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'detected_at' => $validated['timestamp'] ?? now(),
                'detected_by' => auth()->id(),
                'owner_user_id' => $validated['owner_user_id'] ?? null,
                'is_concession_approved' => $validated['is_concession_approved'] ?? false,
                'concession_reason' => $validated['concession_reason'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->recordEvent($ncr->id, 'created', null, 'open', 'Manual NCR created.');
        });

        return response()->json([
            'success' => true,
            'message' => 'NCR created',
            'data' => $ncr
        ], 201);
    }

    public function update(Request $request, ProductionNcr $ncr): JsonResponse
    {
        $validated = $request->validate([
            'defect_code_id' => 'sometimes|nullable|exists:production_defect_codes,id',
            'root_cause_code_id' => 'sometimes|nullable|exists:production_root_cause_codes,id',
            'severity' => 'sometimes|in:minor,major,critical',
            'status' => 'sometimes|in:open,assigned,in_progress,pending_reinspection,closed,cancelled',
            'description' => 'sometimes|string',
            'timestamp' => 'sometimes|nullable|date',
            'shift' => 'sometimes|nullable|string|max:50',
            'raised_by_name' => 'sometimes|nullable|string|max:160',
            'job_order_no' => 'sometimes|nullable|string|max:120',
            'quantity_affected' => 'sometimes|nullable|numeric|min:0',
            'failure_description' => 'sometimes|nullable|string',
            'primary_sop_breached' => 'sometimes|nullable|string|max:200',
            'conformance_type' => 'sometimes|nullable|string|max:80',
            'items_rejected' => 'sometimes|nullable|numeric|min:0',
            'items_rejected_status' => 'sometimes|nullable|in:yes,no,delayed,na',
            'rejects_location' => 'sometimes|nullable|string|max:160',
            'production_impact' => 'sometimes|nullable|string',
            'client_impacted' => 'sometimes|boolean',
            'immediate_action_taken' => 'sometimes|nullable|string',
            'root_cause_category' => 'sometimes|nullable|string|max:120',
            'root_cause_description' => 'sometimes|nullable|string',
            'preventive_action' => 'sometimes|nullable|string',
            'reinspection_performed' => 'sometimes|boolean',
            'reinspection_performed_status' => 'sometimes|nullable|in:yes,no,na,other',
            'reinspection_performed_other' => 'sometimes|nullable|string|max:255|required_if:reinspection_performed_status,other',
            'reinspection_results' => 'sometimes|nullable|string',
            'resolution' => 'sometimes|nullable|string',
            'supervisor_approval' => 'sometimes|boolean',
            'containment_action' => 'sometimes|nullable|string',
            'corrective_action' => 'sometimes|nullable|string',
            'due_date' => 'sometimes|nullable|date',
            'owner_user_id' => 'sometimes|nullable|exists:users,id',
            'is_concession_approved' => 'sometimes|boolean',
            'concession_reason' => 'sometimes|nullable|string',
        ]);

        DB::transaction(function () use ($ncr, $validated) {
            $fromStatus = $ncr->status;

            if (array_key_exists('supervisor_approval', $validated)) {
                $validated['supervisor_approved_by'] = $validated['supervisor_approval'] ? auth()->id() : null;
                $validated['supervisor_approved_at'] = $validated['supervisor_approval'] ? now() : null;
            }

            if (array_key_exists('timestamp', $validated)) {
                $validated['detected_at'] = $validated['timestamp'];
                unset($validated['timestamp']);
            }

            if (array_key_exists('reinspection_performed_status', $validated)) {
                $status = $validated['reinspection_performed_status'];
                $validated['reinspection_performed'] = $status === 'yes';
                if ($status !== 'other') {
                    $validated['reinspection_performed_other'] = null;
                }
            } elseif (array_key_exists('reinspection_performed', $validated)) {
                $validated['reinspection_performed_status'] = $validated['reinspection_performed'] ? 'yes' : 'no';
                $validated['reinspection_performed_other'] = null;
            }

            $ncr->update($validated);

            if (isset($validated['status']) && $validated['status'] !== $fromStatus) {
                $this->recordEvent($ncr->id, 'status_changed', $fromStatus, $validated['status'], 'Status updated.');
            } else {
                $this->recordEvent($ncr->id, 'note_added', null, null, 'NCR details updated.');
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'NCR updated',
            'data' => $ncr->fresh()
        ]);
    }

    public function assign(Request $request, ProductionNcr $ncr): JsonResponse
    {
        $validated = $request->validate([
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'assigned_department' => 'nullable|string|max:120',
            'assigned_workstation' => 'nullable|string|max:120',
            'assignment_role' => 'nullable|string|max:80',
            'notes' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $assignment = null;
        DB::transaction(function () use ($validated, $ncr, &$assignment) {
            $assignment = ProductionNcrAssignment::create([
                'ncr_id' => $ncr->id,
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'assigned_department' => $validated['assigned_department'] ?? null,
                'assigned_workstation' => $validated['assigned_workstation'] ?? null,
                'assignment_role' => $validated['assignment_role'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
                'due_date' => $validated['due_date'] ?? null,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ]);

            $fromStatus = $ncr->status;
            $ncr->update([
                'owner_user_id' => $validated['assigned_to_user_id'] ?? $ncr->owner_user_id,
                'status' => 'assigned',
            ]);

            $this->recordEvent($ncr->id, 'assignment_added', $fromStatus, 'assigned', 'NCR assigned.');
        });

        return response()->json([
            'success' => true,
            'message' => 'NCR assignment created',
            'data' => $assignment
        ], 201);
    }

    public function requestReinspection(ProductionNcr $ncr): JsonResponse
    {
        if ($ncr->status === 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'Closed NCR cannot be moved to reinspection.'
            ], 422);
        }

        $fromStatus = $ncr->status;
        $ncr->update(['status' => 'pending_reinspection']);
        $this->recordEvent($ncr->id, 'reinspection_requested', $fromStatus, 'pending_reinspection', 'Reinspection requested.');

        return response()->json([
            'success' => true,
            'message' => 'NCR moved to pending reinspection',
            'data' => $ncr
        ]);
    }

    public function close(Request $request, ProductionNcr $ncr): JsonResponse
    {
        $validated = $request->validate([
            'closure_type' => 'required|in:permanent_fix,concession,containment_only',
            'verification_result' => 'required|in:passed,failed,conditional',
            'closure_summary' => 'required|string',
            'effectiveness_note' => 'nullable|string',
            'effectiveness_review_required' => 'nullable|boolean',
            'effectiveness_review_date' => 'nullable|date',
            'lessons_learned' => 'nullable|string',
        ]);

        if ($ncr->status !== 'pending_reinspection' && !($ncr->is_concession_approved ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'NCR can only be closed after reinspection, unless concession is approved.'
            ], 422);
        }

        DB::transaction(function () use ($ncr, $validated) {
            ProductionNcrClosure::updateOrCreate(
                ['ncr_id' => $ncr->id],
                [
                    'closure_type' => $validated['closure_type'],
                    'verification_result' => $validated['verification_result'],
                    'closure_summary' => $validated['closure_summary'],
                    'effectiveness_note' => $validated['effectiveness_note'] ?? null,
                    'effectiveness_review_required' => $validated['effectiveness_review_required'] ?? false,
                    'effectiveness_review_date' => $validated['effectiveness_review_date'] ?? null,
                    'lessons_learned' => $validated['lessons_learned'] ?? null,
                    'verified_by' => auth()->id(),
                    'verified_at' => now(),
                    'created_by' => auth()->id(),
                ]
            );

            $fromStatus = $ncr->status;
            $ncr->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => auth()->id(),
            ]);

            $this->recordEvent($ncr->id, 'closed', $fromStatus, 'closed', 'NCR closed.');
        });

        return response()->json([
            'success' => true,
            'message' => 'NCR closed successfully',
            'data' => $ncr->fresh()->load('closure')
        ]);
    }

    public function uploadImage(Request $request, ProductionNcr $ncr): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|image|max:5120',
        ]);

        $file = $validated['image'];
        $path = $file->store("production/ncrs/{$ncr->id}", 'public');

        if ($ncr->image_path && Storage::disk('public')->exists($ncr->image_path)) {
            Storage::disk('public')->delete($ncr->image_path);
        }

        $ncr->update([
            'image_path' => $path,
            'image_original_name' => $file->getClientOriginalName(),
        ]);

        $this->recordEvent($ncr->id, 'note_added', null, null, 'NCR image uploaded.');

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded',
            'data' => [
                'image_path' => $ncr->image_path,
                'image_original_name' => $ncr->image_original_name,
                'image_url' => Storage::disk('public')->url($ncr->image_path),
            ]
        ]);
    }

    public function referenceData(): JsonResponse
    {
        $defectCodes = ProductionDefectCode::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'defect_group', 'default_severity', 'default_stage']);

        $rootCauseCodes = ProductionRootCauseCode::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'cause_group']);

        return response()->json([
            'success' => true,
            'data' => [
                'defect_codes' => $defectCodes,
                'root_cause_codes' => $rootCauseCodes,
            ]
        ]);
    }

    private function recordEvent(
        int $ncrId,
        string $type,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note = null
    ): void {
        ProductionNcrEvent::create([
            'ncr_id' => $ncrId,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'performed_by' => auth()->id(),
            'performed_at' => now(),
        ]);
    }
}
