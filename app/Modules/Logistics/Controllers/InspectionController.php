<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\VehicleInspection;
use App\Modules\Logistics\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InspectionController extends Controller
{
    private array $with = ['vehicle', 'inspector', 'logisticsOfficer'];

    // ── GET /logistics/inspections ────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $inspections = VehicleInspection::with($this->with)
            ->when($request->date,            fn($q) => $q->whereDate('inspection_date', $request->date))
            ->when($request->vehicle_id,      fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->inspection_type, fn($q) => $q->where('inspection_type', $request->inspection_type))
            ->when($request->overall_result,  fn($q) => $q->where('overall_result', $request->overall_result))
            ->when($request->status,          fn($q) => $q->where('status', $request->status))
            ->latest('inspection_date')
            ->paginate(25);

        return response()->json($inspections);
    }

    // ── GET /logistics/inspections/checklist-items ────────────────────────
    // Returns the master checklist so the Vue form can render it dynamically
    public function checklistItems(): JsonResponse
    {
        return response()->json(['data' => VehicleInspection::checklistItems()]);
    }

    // ── GET /logistics/inspections/stats ──────────────────────────────────
    public function stats(Request $request): JsonResponse
    {
        $date = $request->date ?? today()->toDateString();

        return response()->json([
            'today_total'   => VehicleInspection::whereDate('inspection_date', $date)->count(),
            'today_passed'  => VehicleInspection::whereDate('inspection_date', $date)->where('overall_result', 'passed')->count(),
            'today_notes'   => VehicleInspection::whereDate('inspection_date', $date)->where('overall_result', 'passed_with_notes')->count(),
            'today_failed'  => VehicleInspection::whereDate('inspection_date', $date)->where('overall_result', 'failed')->count(),
            'pending_review'=> VehicleInspection::where('status', 'submitted')->count(),
        ]);
    }

    // ── GET /logistics/inspections/{inspection} ───────────────────────────
    public function show(VehicleInspection $inspection): JsonResponse
    {
        $inspection->load($this->with);
        return response()->json(['data' => $inspection]);
    }

    // ── POST /logistics/inspections ───────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id'                  => 'required|exists:vehicles,id',
            'inspection_type'             => 'required|in:pre_trip,post_trip,routine',
            'inspection_date'             => 'required|date',
            'inspection_time'             => 'nullable|date_format:H:i',
            'odometer_reading'            => 'nullable|numeric|min:0',
            'fueling_odometer'            => 'nullable|numeric|min:0',
            'amount_fueled_litres'        => 'nullable|numeric|min:0',
            'checklist'                   => 'required|array',
            'overall_result'              => 'required|in:passed,passed_with_notes,failed',
            'inspector_comments'          => 'nullable|string|max:1000',
            'condition_acceptable'        => 'boolean',
            'defects_repair_immediately'  => 'boolean',
            'defects_repair_few_days'     => 'boolean',
            'submit'                      => 'boolean',
        ]);

        $inspection = VehicleInspection::create([
            ...$validated,
            'inspector_id' => Auth::user()->employee?->id,
            'status'       => $request->boolean('submit') ? 'submitted' : 'draft',
        ]);

        // If vehicle has defects that need immediate repair, flag it
        if ($validated['defects_repair_immediately'] ?? false) {
            // Optionally auto-create a maintenance log draft here
        }

        $inspection->load($this->with);
        return response()->json(['data' => $inspection], 201);
    }

    // ── PATCH /logistics/inspections/{inspection} ─────────────────────────
    public function update(Request $request, VehicleInspection $inspection): JsonResponse
    {
        if ($inspection->status === 'reviewed') {
            return response()->json(['message' => 'Reviewed inspections cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'inspection_type'             => 'sometimes|in:pre_trip,post_trip,routine',
            'inspection_date'             => 'sometimes|date',
            'inspection_time'             => 'nullable|date_format:H:i',
            'odometer_reading'            => 'nullable|numeric|min:0',
            'checklist'                   => 'sometimes|array',
            'overall_result'              => 'sometimes|in:passed,passed_with_notes,failed',
            'inspector_comments'          => 'nullable|string|max:1000',
            'condition_acceptable'        => 'boolean',
            'defects_repair_immediately'  => 'boolean',
            'defects_repair_few_days'     => 'boolean',
        ]);

        $inspection->update($validated);
        $inspection->load($this->with);
        return response()->json(['data' => $inspection]);
    }

    // ── PATCH /logistics/inspections/{inspection}/submit ──────────────────
    public function submit(VehicleInspection $inspection): JsonResponse
    {
        if ($inspection->status !== 'draft') {
            return response()->json(['message' => 'Only draft inspections can be submitted.'], 422);
        }
        $inspection->update(['status' => 'submitted']);
        $inspection->load($this->with);
        return response()->json(['data' => $inspection]);
    }

    // ── PATCH /logistics/inspections/{inspection}/review ──────────────────
    // Logistics officer reviews and signs off
    public function review(Request $request, VehicleInspection $inspection): JsonResponse
    {
        $inspection->update([
            'status'               => 'reviewed',
            'logistics_officer_id' => Auth::user()->employee?->id,
        ]);
        $inspection->load($this->with);
        return response()->json(['data' => $inspection]);
    }

    // ── DELETE /logistics/inspections/{inspection} ────────────────────────
    public function destroy(VehicleInspection $inspection): JsonResponse
    {
        if ($inspection->status === 'reviewed') {
            return response()->json(['message' => 'Reviewed inspections cannot be deleted.'], 422);
        }
        $inspection->delete();
        return response()->json(['message' => 'Inspection deleted.']);
    }
}
