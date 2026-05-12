<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Vehicle;
use App\Http\Resources\VehicleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class VehicleController extends Controller
{
    /**
     * List all vehicles.
     * Supports filtering by status, type, gps_status via query params.
     * e.g. GET /fleet/vehicles?status=active&vehicle_type=truck&gps_status=active
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $vehicles = Vehicle::with('assignedDriver.employee')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->vehicle_type, fn($q) => $q->where('vehicle_type', $request->vehicle_type))
            ->when($request->gps_status, fn($q) => $q->where('gps_status', $request->gps_status))
            ->latest()
            ->paginate(20);

        return VehicleResource::collection($vehicles);
    }

    /**
     * Add a new vehicle.
     */
   public function store(Request $request): VehicleResource|JsonResponse
{
    if (!$this->canManageVehicles()) {
        return response()->json(['message' => 'Only Super Admin or Admin can add vehicles.'], 403);
    }

    $validated = $request->validate([
        'plate_number'       => 'required|string|max:20|unique:vehicles,plate_number',
        'make'               => 'nullable|string|max:100',   // ← add
        'model'              => 'nullable|string|max:100',   // ← add
        'vehicle_type'       => 'required|in:truck,van,pickup,motorcycle,trailer,other',
        'capacity_kg'        => 'required|numeric|min:0',
        'fuel_type'          => 'required|in:diesel,petrol,electric,hybrid',
        'insurance_expiry'   => 'required|date|after:today',
        'odometer_km'        => 'sometimes|numeric|min:0',
        'gps_status'         => 'sometimes|in:active,inactive',
        'status'             => 'sometimes|in:active,inactive,maintenance,booked',
        'assigned_driver_id' => 'sometimes|nullable|exists:drivers,id',
        'photo_front'        => 'nullable|image|max:4096',   // ← add
        'photo_side'         => 'nullable|image|max:4096',   // ← add
    ]);

    // ← add photo handling
    if ($request->hasFile('photo_front')) {
        $validated['photo_front'] = $request->file('photo_front')
            ->store('vehicles/photos', 'public');
    }
    if ($request->hasFile('photo_side')) {
        $validated['photo_side'] = $request->file('photo_side')
            ->store('vehicles/photos', 'public');
    }

    $vehicle = Vehicle::create($validated);
    $vehicle->load('assignedDriver.employee');

    return new VehicleResource($vehicle);
}

    /**
     * View a single vehicle.
     */
    public function show(Vehicle $vehicle): VehicleResource
    {
        $vehicle->load('assignedDriver.employee');

        return new VehicleResource($vehicle);
    }

    /**
     * Update vehicle details.
     */
    public function update(Request $request, Vehicle $vehicle): VehicleResource|JsonResponse
{
    if (!$this->canManageVehicles()) {
        return response()->json(['message' => 'Only Super Admin or Admin can edit vehicles.'], 403);
    }

    $validated = $request->validate([
        'plate_number'       => 'sometimes|string|max:20|unique:vehicles,plate_number,' . $vehicle->id,
        'make'               => 'nullable|string|max:100',   // ← add
        'model'              => 'nullable|string|max:100',   // ← add
        'vehicle_type'       => 'sometimes|in:truck,van,pickup,motorcycle,trailer,other',
        'capacity_kg'        => 'sometimes|numeric|min:0',
        'fuel_type'          => 'sometimes|in:diesel,petrol,electric,hybrid',
        'insurance_expiry'   => 'sometimes|date',
        'odometer_km'        => 'sometimes|numeric|min:0',
        'gps_status'         => 'sometimes|in:active,inactive',
        'status'             => 'sometimes|in:active,inactive,maintenance,booked',
        'assigned_driver_id' => 'sometimes|nullable|exists:drivers,id',
        'photo_front'        => 'nullable|image|max:4096',   // ← add
        'photo_side'         => 'nullable|image|max:4096',   // ← add
    ]);

    // ← add photo handling
    if ($request->hasFile('photo_front')) {
        if ($vehicle->photo_front) {
            Storage::disk('public')->delete($vehicle->photo_front);
        }
        $validated['photo_front'] = $request->file('photo_front')
            ->store('vehicles/photos', 'public');
    }
    if ($request->hasFile('photo_side')) {
        if ($vehicle->photo_side) {
            Storage::disk('public')->delete($vehicle->photo_side);
        }
        $validated['photo_side'] = $request->file('photo_side')
            ->store('vehicles/photos', 'public');
    }

    $vehicle->update($validated);
    $vehicle->load('assignedDriver.employee');

    return new VehicleResource($vehicle);
}

    /**
     * Update GPS coordinates only.
     * Separate endpoint so the GPS tracker can ping without touching other fields.
     * PATCH /fleet/vehicles/{vehicle}/gps
     */
    public function updateGps(Request $request, Vehicle $vehicle): VehicleResource
    {
        $validated = $request->validate([
            'gps_lat'    => 'required|numeric|between:-90,90',
            'gps_lng'    => 'required|numeric|between:-180,180',
            'gps_status' => 'sometimes|in:active,inactive',
        ]);

        $vehicle->update(array_merge($validated, [
            'gps_last_updated' => now(),
        ]));

        return new VehicleResource($vehicle);
    }

    /**
     * Soft-delete a vehicle.
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if (!$this->canManageVehicles()) {
            return response()->json(['message' => 'Only Super Admin or Admin can remove vehicles.'], 403);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Vehicle removed successfully.']);
    }

    /**
     * Only Super Admin and Admin can create, edit, or delete vehicles.
     * Logistics role is view-only.
     */
    private function canManageVehicles(): bool
    {
        $user  = Auth::user();
        $roles = is_array($user?->roles)
            ? $user->roles
            : ($user?->roles?->pluck('name')->toArray() ?? []);

        return array_intersect(['Super Admin', 'Admin'], $roles) !== [];
    }
}