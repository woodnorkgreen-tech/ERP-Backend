<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Driver;
use App\Http\Resources\DriverResource;

use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverController extends Controller
{
    /**
     * List all drivers with their employee info.
     */
    public function index(): AnonymousResourceCollection
    {
        $drivers = Driver::with('employee')
            ->latest()
            ->paginate(20);

        return DriverResource::collection($drivers);
    }

    /**
     * Add a new driver.
     * Frontend sends employee_id + license fields.
     * Name and phone are pulled from the employee relationship.
     */
    public function store(Request $request): DriverResource|JsonResponse
    {
        $validated = $request->validate([
            'employee_id'    => 'required|exists:employees,id|unique:drivers,employee_id',
            'license_number' => 'required|string|max:50|unique:drivers,license_number',
            'license_expiry' => 'required|date|after:today',
            'status'         => 'sometimes|in:active,inactive,on_leave',
        ]);

        $driver = Driver::create($validated);
        $driver->load('employee');

        return new DriverResource($driver);
    }

    /**
     * View a single driver.
     */
    public function show(Driver $driver): DriverResource
    {
        $driver->load('employee');

        return new DriverResource($driver);
    }

    /**
     * Update license number, expiry, or status.
     * employee_id is intentionally NOT updatable — reassign via delete + new record.
     */
    public function update(Request $request, Driver $driver): DriverResource
    {
        $validated = $request->validate([
            'license_number' => 'sometimes|string|max:50|unique:drivers,license_number,' . $driver->id,
            'license_expiry' => 'sometimes|date|after:today',
            'status'         => 'sometimes|in:active,inactive,on_leave',
        ]);

        $driver->update($validated);
        $driver->load('employee');

        return new DriverResource($driver);
    }

    /**
     * Soft-delete a driver record.
     */
    public function destroy(Driver $driver): JsonResponse
    {
        $driver->delete();

        return response()->json(['message' => 'Driver removed successfully.']);
    }

    /**
     * Employees available to be assigned as drivers.
     * Filters: active employees only, not already a driver.
     * Used to populate the Add Driver employee dropdown.
     */
    public function availableEmployees(): JsonResponse
    {
        $assignedEmployeeIds = Driver::pluck('employee_id');

        $employees = Employee::active()                          // uses scopeActive() from your model
            ->whereNotIn('id', $assignedEmployeeIds)
            ->select('id', 'first_name', 'last_name', 'phone')  // only what the dropdown needs
            ->get()
            ->map(fn($e) => [
                'id'    => $e->id,
                'name'  => $e->name,   // triggers getNameAttribute()
                'phone' => $e->phone,
            ]);

        return response()->json($employees);
    }
}
