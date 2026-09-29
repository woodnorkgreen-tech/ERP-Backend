<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Models\Driver;
use App\Modules\Logistics\Models\Vehicle;
use App\Modules\Logistics\Models\Delivery;
use App\Http\Resources\TripRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class TripRequestController extends Controller
{
    private array $with = [
        'project',
        'requestedBy',
        'approvedBy',
        'assignedDriver.employee',
        'assignedVehicle',
        'assignedBy',
        'loadingResponsible',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $user        = Auth::user();
        $isLogistics = $this->isLogisticsTeam($user);
        $isClientService = $user?->hasAnyRole(['Client Service']) ?? false;

        $query = TripRequest::with($this->with)
            // Project-linked trips are the merged Logistics Log — everyone
            // needs to see the schedule for those, the same way the old Log
            // was visible to the whole team. A personal ("other") request
            // with no project stays private to its requester unless you're
            // Logistics or Client Service, who see everything regardless.
            ->when(!$isLogistics && !$isClientService, fn($q) => $q->where(function ($q2) use ($user) {
                $q2->whereNotNull('project_id')
                    ->orWhere('requested_by_id', $user->employee?->id);
            }))
            ->when($request->status,     fn($q) => $q->where('status', $request->status))
            ->when($request->priority,   fn($q) => $q->where('priority', $request->priority))
            ->when($request->project_id, fn($q) => $q->where('project_id', $request->project_id))
            ->latest()
            ->paginate(25);

        return TripRequestResource::collection($query);
    }

    public function store(Request $request): TripRequestResource|JsonResponse
    {
        $validated = $request->validate([
            'context_type'          => 'required|in:project,other',
            'transport_arrangement' => 'sometimes|in:company,client',
            'project_id'            => 'required_if:context_type,project|nullable|exists:project_enquiries,id',
            'delivery_type_label'   => 'required|string|max:150',
            'requested_by_id'       => 'required|exists:employees,id',
            'priority'              => 'required|in:low,medium,high,emergency',
            'pickup_location'       => 'required|string|max:300',
            'pickup_lat'            => 'nullable|numeric|between:-90,90',
            'pickup_lng'            => 'nullable|numeric|between:-180,180',
            'destination'           => 'required|string|max:300',
            'destination_lat'       => 'nullable|numeric|between:-90,90',
            'destination_lng'       => 'nullable|numeric|between:-180,180',
            'required_date'         => 'required|date|after_or_equal:today',
            'loading_time'          => 'nullable|string|max:20',
            'departure_time'        => 'nullable|string|max:20',
            // A literal "to be communicated" string is never sent for
            // setdown_time — the client leaves it out and says so in notes
            // instead, matching how the old Log avoided this exact field
            // failing "must be a valid date" validation.
            'setdown_time'          => 'nullable|date',
            'vehicle_note'          => 'nullable|string|max:150',
            // Backward-calculation from the delivery deadline (optional —
            // a trip can still just use the plain loading_time/
            // departure_time fields above instead).
            'required_delivery_at'      => 'nullable|date',
            'estimated_loading_minutes' => 'nullable|integer|min:0|max:1440',
            // Purely informational tag driving the create form's minute
            // presets — doesn't change the calculation itself.
            'load_size'                 => 'nullable|in:small,medium,large',
            'estimated_travel_minutes'  => 'nullable|integer|min:0|max:1440',
            'buffer_minutes'            => 'nullable|integer|min:0|max:1440',
            'loading_responsible_id'    => 'nullable|exists:employees,id',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        $trip = TripRequest::create($validated);
        $trip->load($this->with);

        return new TripRequestResource($trip);
    }

    public function show(TripRequest $tripRequest): TripRequestResource
    {
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    public function update(Request $request, TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if ($tripRequest->status !== 'requested') {
            return response()->json(['message' => 'Only pending requests can be edited.'], 422);
        }

        $validated = $request->validate([
            'context_type'          => 'sometimes|in:project,other',
            'transport_arrangement' => 'sometimes|in:company,client',
            'project_id'            => 'nullable|exists:project_enquiries,id',
            'delivery_type_label'   => 'sometimes|string|max:150',
            'requested_by_id'       => 'sometimes|exists:employees,id',
            'priority'              => 'sometimes|in:low,medium,high,emergency',
            'pickup_location'       => 'sometimes|string|max:300',
            'pickup_lat'            => 'nullable|numeric|between:-90,90',
            'pickup_lng'            => 'nullable|numeric|between:-180,180',
            'destination'           => 'sometimes|string|max:300',
            'destination_lat'       => 'nullable|numeric|between:-90,90',
            'destination_lng'       => 'nullable|numeric|between:-180,180',
            'required_date'         => 'sometimes|date|after_or_equal:today',
            'loading_time'          => 'nullable|string|max:20',
            'departure_time'        => 'nullable|string|max:20',
            'setdown_time'          => 'nullable|date',
            'vehicle_note'          => 'nullable|string|max:150',
            'required_delivery_at'      => 'nullable|date',
            'estimated_loading_minutes' => 'nullable|integer|min:0|max:1440',
            'load_size'                 => 'nullable|in:small,medium,large',
            'estimated_travel_minutes'  => 'nullable|integer|min:0|max:1440',
            'buffer_minutes'            => 'nullable|integer|min:0|max:1440',
            'loading_responsible_id'    => 'nullable|exists:employees,id',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        $tripRequest->update($validated);
        $tripRequest->load($this->with);

        return new TripRequestResource($tripRequest);
    }

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

    public function assign(Request $request, TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($tripRequest->status !== 'approved') {
            return response()->json(['message' => 'Only approved requests can be assigned.'], 422);
        }

        // A client-arranged pickup (the Log's old "Client to pick" option)
        // has no company driver/vehicle to assign — it just needs a note on
        // who's collecting, so it can move straight to "assigned" without
        // the fleet-availability checks below.
        if ($tripRequest->transport_arrangement === 'client') {
            $validated = $request->validate([
                'vehicle_note'     => 'nullable|string|max:150',
                'assignment_notes' => 'nullable|string|max:500',
            ]);

            $tripRequest->update([
                'status'           => 'assigned',
                'vehicle_note'     => $validated['vehicle_note'] ?? $tripRequest->vehicle_note,
                'assigned_by_id'   => Auth::user()->employee?->id,
                'assigned_at'      => now(),
                'assignment_notes' => $validated['assignment_notes'] ?? null,
            ]);

            $tripRequest->load($this->with);
            return new TripRequestResource($tripRequest);
        }

        $validated = $request->validate([
            'driver_id'        => 'required|exists:drivers,id',
            'vehicle_id'       => 'required|exists:vehicles,id',
            'assignment_notes' => 'nullable|string|max:500',
        ]);

        $driverBusy = Delivery::where('driver_id', $validated['driver_id'])
            ->whereIn('status', ['pending', 'in_transit'])->exists();
        if ($driverBusy) {
            return response()->json(['message' => 'Driver is already assigned to an active delivery.'], 422);
        }

        $vehicleBusy = Delivery::where('vehicle_id', $validated['vehicle_id'])
            ->whereIn('status', ['pending', 'in_transit'])->exists();
        if ($vehicleBusy) {
            return response()->json(['message' => 'Vehicle is already in use on an active delivery.'], 422);
        }

        $vehicle = Vehicle::find($validated['vehicle_id']);
        if ($vehicle && $vehicle->status === 'maintenance') {
            return response()->json(['message' => 'Vehicle is currently under maintenance.'], 422);
        }

        Vehicle::where('id', $validated['vehicle_id'])->update(['status' => 'booked']);

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

    public function start(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($tripRequest->status !== 'assigned') {
            return response()->json(['message' => 'Only assigned trips can be started.'], 422);
        }

        $tripRequest->update(['status' => 'in_transit', 'started_at' => now()]);
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    public function complete(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($tripRequest->status !== 'in_transit') {
            return response()->json(['message' => 'Only in-transit trips can be completed.'], 422);
        }

        if ($tripRequest->assigned_vehicle_id) {
            Vehicle::where('id', $tripRequest->assigned_vehicle_id)->update(['status' => 'active']);
        }

        $tripRequest->update(['status' => 'completed', 'completed_at' => now()]);
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    public function cancel(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!in_array($tripRequest->status, ['requested', 'approved', 'assigned'])) {
            return response()->json(['message' => 'This request cannot be cancelled.'], 422);
        }

        if ($tripRequest->assigned_vehicle_id) {
            Vehicle::where('id', $tripRequest->assigned_vehicle_id)->update(['status' => 'active']);
        }

        $tripRequest->update(['status' => 'cancelled']);
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    /**
     * The PO/responsible person confirms loading has actually started —
     * the "check when the loading has started" half of Kevin's request.
     * Open to the assigned responsible person (falling back to the
     * original requester if none was set) or the Logistics team, not
     * locked to any particular trip status, since loading can genuinely
     * start any time after the trip is planned.
     */
    public function markLoadingStarted(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->canMarkLoading(Auth::user(), $tripRequest)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if ($tripRequest->loading_started_at) {
            return response()->json(['message' => 'Loading was already marked as started.'], 422);
        }

        $tripRequest->update(['loading_started_at' => now()]);
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    /**
     * The other half — marks when loading actually finished, so it can be
     * compared against departure_by for the overdue check.
     */
    public function markLoadingEnded(TripRequest $tripRequest): TripRequestResource|JsonResponse
    {
        if (!$this->canMarkLoading(Auth::user(), $tripRequest)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        if (!$tripRequest->loading_started_at) {
            return response()->json(['message' => 'Mark loading as started first.'], 422);
        }
        if ($tripRequest->loading_ended_at) {
            return response()->json(['message' => 'Loading was already marked as ended.'], 422);
        }

        $tripRequest->update(['loading_ended_at' => now()]);
        $tripRequest->load($this->with);
        return new TripRequestResource($tripRequest);
    }

    private function canMarkLoading($user, TripRequest $tripRequest): bool
    {
        if ($this->isLogisticsTeam($user)) {
            return true;
        }
        $employeeId = $user?->employee?->id;
        if (!$employeeId) {
            return false;
        }
        $responsible = $tripRequest->loading_responsible_id ?: $tripRequest->requested_by_id;
        return $employeeId === $responsible;
    }

    public function destroy(TripRequest $tripRequest): JsonResponse
    {
        if (!$this->isLogisticsTeam(Auth::user())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $tripRequest->delete();
        return response()->json(['message' => 'Trip request deleted.']);
    }

    private function isLogisticsTeam($user): bool
    {
        $roles = $user?->roles ?? [];
        $roleNames = is_array($roles)
            ? $roles
            : $roles->pluck('name')->toArray();

        return array_intersect([
            'Logistics',
            'Logistics Officer',
            'Logistics Manager',
            'Super Admin',
            'Admin',
        ], $roleNames) !== [];
    }
}
