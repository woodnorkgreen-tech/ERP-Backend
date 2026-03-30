<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Models\Driver;
use App\Modules\Logistics\Models\Vehicle;
use App\Http\Resources\TripRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class TripRequestController extends Controller
{
    /**
     * Default eager loads used across read methods.
     */
    private array $with = [
    'project',          // ← just load the project directly
    'requestedBy',
    'approvedBy',
    'assignedDriver.employee',
    'assignedVehicle',
    'assignedBy',
];
    /**
     * GET /logistics/trip-requests
     *
     * Logistics team sees all requests.
     * Regular employees see only their own.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user      = Auth::user();
        $isLogistics = $this->isLogisticsTeam($user);

        $query = TripRequest::with($this->with)
            ->when(!$isLogistics, fn($q) => $q->where('requested_by_id', $user->employee?->id))
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->priority, fn($q) => $q->where('priority', $request->priority))
            ->when($request->project_id, fn($q) => $q->where('project_id', $request->project_id))
            ->latest()
            ->paginate(25);

        return TripRequestResource::collection($query);
    }

    /**
     * POST /logistics/trip-requests
     * Any authenticated employee can create a request.
     */
    public function store(Request $request): TripRequestResource|JsonResponse
    {
        $validated = $request->validate([
            'context_type'        => 'required|in:project,other',
            'project_id'          => 'required_if:context_type,project|nullable|exists:projects,id',
            'delivery_type_label' => 'required|string|max:150',
            'requested_by_id'     => 'required|exists:employees,id',
            'priority'            => 'required|in:low,medium,high,emergency',
            'pickup_location'     => 'required|string|max:300',
            'pickup_lat'          => 'nullable|numeric|between:-90,90',
            'pickup_lng'          => 'nullable|numeric|between:-180,180',
            'destination'         => 'required|string|max:300',
            'destination_lat'     => 'nullable|numeric|between:-90,90',
            'destination_lng'     => 'nullable|numeric|between:-180,180',
            'required_date'       => 'required|date|after_or_equal:today',
            'notes'               => 'nullable|string|max:1000',
        ]);

        $trip = TripRequest::create($validated);
        $trip->load($this->with);

        return new TripRequestResource($trip);
    }

    /**
     * GET /logistics/trip-requests/{trip}
     */
    public function show(TripRequest $tripRequest): TripRequestResource
    {
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    /**
     * PUT/PATCH /logistics/trip-requests/{trip}
     * Only allowed when status is still 'requested'.
     */
    public function update(Request $request, TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if ($tripRequest->status !== 'requested') {
            return response()->json([
                'message' => 'Only pending requests can be edited.'
            ], 422);
        }

        $validated = $request->validate([
            'context_type'        => 'sometimes|in:project,other',
            'project_id'          => 'nullable|exists:projects,id',
            'delivery_type_label' => 'sometimes|string|max:150',
            'requested_by_id'     => 'sometimes|exists:employees,id',
            'priority'            => 'sometimes|in:low,medium,high,emergency',
            'pickup_location'     => 'sometimes|string|max:300',
            'pickup_lat'          => 'nullable|numeric|between:-90,90',
            'pickup_lng'          => 'nullable|numeric|between:-180,180',
            'destination'         => 'sometimes|string|max:300',
            'destination_lat'     => 'nullable|numeric|between:-90,90',
            'destination_lng'     => 'nullable|numeric|between:-180,180',
            'required_date'       => 'sometimes|date|after_or_equal:today',
            'notes'               => 'nullable|string|max:1000',
        ]);

        $tripRequest->update($validated);
        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * PATCH /logistics/trip-requests/{trip}/approve
     * Logistics team only.
     */
    public function approve(Request $request, TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($tripRequest->status !== 'requested') {
            return response()->json(['message' => 'Only pending requests can be approved.'], 422);
        }

        $tripRequest->update([
            'status'         => 'approved',
            'approved_by_id' => Auth::user()->employee?->id,
            'approved_at'    => now(),
        ]);

        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * PATCH /logistics/trip-requests/{trip}/reject
     * Logistics team only.
     */
    public function reject(Request $request, TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($tripRequest->status !== 'requested') {
            return response()->json(['message' => 'Only pending requests can be rejected.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $tripRequest->update([
            'status'           => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'approved_by_id'   => Auth::user()->employee?->id,
            'approved_at'      => now(),
        ]);

        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * PATCH /logistics/trip-requests/{trip}/assign
     * Logistics team only. Assigns a driver and vehicle.
     */
    public function assign(Request $request, TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($tripRequest->status !== 'approved') {
            return response()->json(['message' => 'Only approved requests can be assigned.'], 422);
        }

        $validated = $request->validate([
            'driver_id'        => 'required|exists:drivers,id',
            'vehicle_id'       => 'required|exists:vehicles,id',
            'assignment_notes' => 'nullable|string|max:500',
        ]);

        // Mark vehicle as booked
        Vehicle::where('id', $validated['vehicle_id'])
            ->update(['status' => 'booked']);

        $tripRequest->update([
            'status'              => 'assigned',
            'assigned_driver_id'  => $validated['driver_id'],
            'assigned_vehicle_id' => $validated['vehicle_id'],
            'assigned_by_id'      => Auth::user()->employee?->id,
            'assigned_at'         => now(),
            'assignment_notes'    => $validated['assignment_notes'] ?? null,
        ]);

        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * PATCH /logistics/trip-requests/{trip}/start
     * Marks trip as in_transit.
     */
    public function start(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($tripRequest->status !== 'assigned') {
            return response()->json(['message' => 'Only assigned trips can be started.'], 422);
        }

        $tripRequest->update([
            'status'     => 'in_transit',
            'started_at' => now(),
        ]);

        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * PATCH /logistics/trip-requests/{trip}/complete
     * Marks trip as completed and frees the vehicle.
     */
    public function complete(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($tripRequest->status !== 'in_transit') {
            return response()->json(['message' => 'Only in-transit trips can be completed.'], 422);
        }

        // Free the vehicle
        if ($tripRequest->assigned_vehicle_id) {
            Vehicle::where('id', $tripRequest->assigned_vehicle_id)
                ->update(['status' => 'active']);
        }

        $tripRequest->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * PATCH /logistics/trip-requests/{trip}/cancel
     * Owner or logistics team can cancel a non-completed request.
     */
    public function cancel(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        $allowedStatuses = ['requested', 'approved', 'assigned'];

        if (!in_array($tripRequest->status, $allowedStatuses)) {
            return response()->json(['message' => 'This request cannot be cancelled.'], 422);
        }

        // Free vehicle if it was assigned
        if ($tripRequest->assigned_vehicle_id) {
            Vehicle::where('id', $tripRequest->assigned_vehicle_id)
                ->update(['status' => 'active']);
        }

        $tripRequest->update(['status' => 'cancelled']);
        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

    /**
     * DELETE /logistics/trip-requests/{trip}
     * Soft delete — logistics team only.
     */
    public function destroy(TripRequest $tripRequest): JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $tripRequest->delete();

        return response()->json(['message' => 'Trip request deleted.']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function isLogisticsTeam($user): bool
    {
        $roles = $user?->roles ?? [];
        $roleNames = is_array($roles)
            ? $roles
            : $roles->pluck('name')->toArray();

        return array_intersect(['Logistics', 'Super Admin', 'Admin'], $roleNames) !== [];
    }
}
