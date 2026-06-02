<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\VehicleMaintenanceLog;
use App\Modules\Logistics\Models\Vehicle;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class MaintenanceController extends Controller
{
    private array $with = [
        'vehicle',
        'driverOnDuty',
        'loggedBy',
        'approvedBy',
        'confirmedBy',
    ];

    // ─── GET /logistics/maintenance ──────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $logs = VehicleMaintenanceLog::with($this->with)
            ->when($request->status,          fn($q) => $q->where('status', $request->status))
            ->when($request->vehicle_id,      fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->activity_type,   fn($q) => $q->where('activity_type', $request->activity_type))
            ->when($request->maintenance_type,fn($q) => $q->where('maintenance_type', $request->maintenance_type))
            ->latest()
            ->paginate(25);

        return response()->json($logs);
    }

    // ─── GET /logistics/maintenance/{log} ────────────────────────────────────
    public function show(VehicleMaintenanceLog $maintenance): JsonResponse
    {
        $maintenance->load($this->with);
        return response()->json(['data' => $maintenance]);
    }

    // ─── POST /logistics/maintenance ─────────────────────────────────────────
    // Driver or Logistics Lead logs a new maintenance entry
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id'          => 'required|exists:vehicles,id',
            'driver_on_duty_id'   => 'nullable|exists:employees,id',
            'activity_type'       => 'required|in:maintenance,repair,inspection,fuel',
            'maintenance_type'    => 'required|in:routine,emergency',
            'odometer_reading'    => 'nullable|numeric|min:0',
            'description'         => 'required|string|max:2000',
            'cause_of_failure'    => 'nullable|in:wear_and_tear,accident,negligence,unknown,routine_check,other',
            'service_date'        => 'nullable|date',
            'downtime_days'       => 'nullable|integer|min:0',
        ]);

        $log = VehicleMaintenanceLog::create([
            ...$validated,
            'logged_by_id' => Auth::user()->employee?->id,
            'status'       => 'draft',
        ]);

        $log->load($this->with);
        return response()->json(['data' => $log], 201);
    }

    // ─── PATCH /logistics/maintenance/{log} ──────────────────────────────────
    // General update (only if draft or submitted)
    public function update(Request $request, VehicleMaintenanceLog $maintenance): JsonResponse
    {
        if (!in_array($maintenance->status, ['draft', 'submitted'])) {
            return response()->json(['message' => 'Cannot edit a log that has been costed or approved.'], 422);
        }

        $validated = $request->validate([
            'vehicle_id'        => 'sometimes|exists:vehicles,id',
            'driver_on_duty_id' => 'nullable|exists:employees,id',
            'activity_type'     => 'sometimes|in:maintenance,repair,inspection,fuel',
            'maintenance_type'  => 'sometimes|in:routine,emergency',
            'odometer_reading'  => 'nullable|numeric|min:0',
            'description'       => 'sometimes|string|max:2000',
            'cause_of_failure'  => 'nullable|in:wear_and_tear,accident,negligence,unknown,routine_check,other',
            'service_date'      => 'nullable|date',
            'downtime_days'     => 'nullable|integer|min:0',
        ]);

        $maintenance->update($validated);
        $maintenance->load($this->with);
        return response()->json(['data' => $maintenance]);
    }

    // ─── PATCH /logistics/maintenance/{log}/submit ────────────────────────────
    // Driver/Lead submits to Procurement
    public function submit(VehicleMaintenanceLog $maintenance): JsonResponse
    {
        if ($maintenance->status !== 'draft') {
            return response()->json(['message' => 'Only draft logs can be submitted.'], 422);
        }

        $maintenance->update(['status' => 'submitted']);
        $maintenance->load($this->with);
        return response()->json(['message' => 'Submitted to procurement.', 'data' => $maintenance]);
    }

    // ─── PATCH /logistics/maintenance/{log}/cost ─────────────────────────────
    // Procurement adds cost breakdown + vendor + next service date
    public function addCost(Request $request, VehicleMaintenanceLog $maintenance): JsonResponse
    {
        if ($maintenance->status !== 'submitted') {
            return response()->json(['message' => 'Log must be submitted before costing.'], 422);
        }

        $validated = $request->validate([
            'service_provider'    => 'required|string|max:200',
            'cost_breakdown'      => 'required|array|min:1',
            'cost_breakdown.*.item' => 'required|string',
            'cost_breakdown.*.cost' => 'required|numeric|min:0',
            'total_cost'          => 'required|numeric|min:0',
            'next_service_due'    => 'nullable|date',
            'next_service_notes'  => 'nullable|string|max:500',
            'downtime_days'       => 'nullable|integer|min:0',
        ]);

        $maintenance->update([
            ...$validated,
            'status' => 'costed',
        ]);

        $maintenance->load($this->with);
        return response()->json(['message' => 'Cost added. Sent to finance for approval.', 'data' => $maintenance]);
    }

    // ─── PATCH /logistics/maintenance/{log}/approve ───────────────────────────
    // Finance or Admin approves
    public function approve(VehicleMaintenanceLog $maintenance): JsonResponse
    {
        if ($maintenance->status !== 'costed') {
            return response()->json(['message' => 'Log must be costed before approval.'], 422);
        }

        $maintenance->update([
            'status'         => 'approved',
            'approved_by_id' => Auth::user()->employee?->id,
            'approved_at'    => now(),
        ]);

        $maintenance->load($this->with);
        return response()->json(['message' => 'Approved.', 'data' => $maintenance]);
    }

    // ─── PATCH /logistics/maintenance/{log}/complete ──────────────────────────
    // Logistics Lead confirms work is done, frees the vehicle
    public function complete(Request $request, VehicleMaintenanceLog $maintenance): JsonResponse
    {
        if ($maintenance->status !== 'approved') {
            return response()->json(['message' => 'Log must be approved before completion.'], 422);
        }

        $validated = $request->validate([
            'service_date'       => 'required|date',
            'next_service_due'   => 'nullable|date',
            'next_service_notes' => 'nullable|string|max:500',
        ]);

        $maintenance->update([
            ...$validated,
            'status'           => 'completed',
            'confirmed_by_id'  => Auth::user()->employee?->id,
            'confirmed_at'     => now(),
        ]);

        // Vehicle freed automatically via model observer
        $maintenance->load($this->with);
        return response()->json(['message' => 'Completed. Vehicle is now available.', 'data' => $maintenance]);
    }

    // ─── PATCH /logistics/maintenance/{log}/reject ────────────────────────────
    public function reject(Request $request, VehicleMaintenanceLog $maintenance): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $maintenance->update([
            'status'           => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by_id'   => Auth::user()->employee?->id,
            'approved_at'      => now(),
        ]);

        // Free vehicle back to active on rejection
        Vehicle::where('id', $maintenance->vehicle_id)->update(['status' => 'active']);

        $maintenance->load($this->with);
        return response()->json(['message' => 'Rejected.', 'data' => $maintenance]);
    }

    // ─── DELETE /logistics/maintenance/{log} ─────────────────────────────────
    public function destroy(VehicleMaintenanceLog $maintenance): JsonResponse
    {
        if (!in_array($maintenance->status, ['draft', 'rejected'])) {
            return response()->json(['message' => 'Only draft or rejected logs can be deleted.'], 422);
        }

        // Free vehicle if still in maintenance
        $vehicle = Vehicle::find($maintenance->vehicle_id);
        if ($vehicle && $vehicle->status === 'maintenance') {
            $vehicle->update(['status' => 'active']);
        }

        $maintenance->delete();
        return response()->json(['message' => 'Log deleted.']);
    }

    // ─── GET /logistics/maintenance/stats ─────────────────────────────────────
    public function stats(): JsonResponse
    {
        return response()->json([
            'draft'     => VehicleMaintenanceLog::where('status', 'draft')->count(),
            'submitted' => VehicleMaintenanceLog::where('status', 'submitted')->count(),
            'costed'    => VehicleMaintenanceLog::where('status', 'costed')->count(),
            'approved'  => VehicleMaintenanceLog::where('status', 'approved')->count(),
            'completed' => VehicleMaintenanceLog::where('status', 'completed')->count(),
            'rejected'  => VehicleMaintenanceLog::where('status', 'rejected')->count(),
        ]);
    }

    // ─── GET /logistics/maintenance/vehicles-in-maintenance ───────────────────
    // List all vehicles currently in maintenance for the maintenance page header
    public function vehiclesInMaintenance(): JsonResponse
    {
        $vehicles = Vehicle::where('status', 'maintenance')
            ->with(['assignedDriver.employee'])
            ->get();

        return response()->json(['data' => $vehicles]);
    }
}
