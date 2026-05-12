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
     * Supports ?include_delivery=1 to load active delivery
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Driver::with('employee');
        
        // ✅ ADD THIS - Load active delivery if requested
        if ($request->boolean('include_delivery')) {
            $query->with('activeDelivery.vehicle', 'activeDelivery.stops');
        }
        
        // ✅ ADD THIS - Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        // ✅ ADD THIS - Search functionality
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('employee', function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            })->orWhere('license_number', 'like', "%{$search}%");
        }
        
        $drivers = $query->latest()->paginate(20);

        return DriverResource::collection($drivers);
    }

    /**
     * Add a new driver.
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
        $driver->load('employee', 'activeDelivery.vehicle', 'activeDelivery.stops');
        return new DriverResource($driver);
    }

    /**
     * Update license number, expiry, or status.
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
     * Returns plain array (not wrapped in 'data') for frontend consumption.
     */
    public function availableEmployees(): JsonResponse
    {
        $assignedEmployeeIds = Driver::pluck('employee_id');

        $employees = Employee::active()
            ->whereNotIn('id', $assignedEmployeeIds)
            ->select('id', 'first_name', 'last_name', 'phone')
            ->get()
            ->map(fn($e) => [
                'id'    => $e->id,
                'name'  => $e->name,
                'phone' => $e->phone,
            ]);

        // ✅ RETURN PLAIN ARRAY (not wrapped in 'data')
        return response()->json($employees);
    }
}