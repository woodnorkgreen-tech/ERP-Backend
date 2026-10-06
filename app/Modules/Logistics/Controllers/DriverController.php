<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Driver;
use App\Http\Resources\DriverResource;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Throwable;

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
        $this->syncDriverRole($driver, true);

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
        $this->syncDriverRole($driver, false);
        $driver->delete();
        return response()->json(['message' => 'Driver removed successfully.']);
    }

    /**
     * HR employees who can be registered as drivers.
     *
     * Pulled from the HR employee list: active employees whose position
     * is a driver role (Driver, Co-Driver…) and who aren't registered yet.
     * Pass ?all=1 to list every active employee instead (e.g. someone whose
     * HR position isn't titled "Driver").
     * Returns a plain array (not wrapped in 'data') for the frontend.
     */
    public function availableEmployees(Request $request): JsonResponse
    {
        $registered = Driver::pluck('employee_id');

        $employees = Employee::active()
            ->whereNotIn('id', $registered)
            ->when(!$request->boolean('all'), fn ($q) => $q->where('position', 'like', '%driver%'))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'phone', 'position'])
            ->map(fn ($e) => [
                'id'       => $e->id,
                'name'     => $e->name,
                'phone'    => $e->phone,
                'position' => $e->position,
            ]);

        return response()->json($employees);
    }

    /**
     * Keep the "Driver" role in step with the driver list: registering an
     * employee as a driver gives their login the Driver role (so they only
     * see their own assignments); removing the driver takes it away again.
     * Never blocks the save — a missing login is simply skipped.
     */
    private function syncDriverRole(Driver $driver, bool $give): void
    {
        try {
            $user = $driver->user
                ?? ($driver->employee?->email
                    ? \App\Models\User::where('email', $driver->employee->email)->first()
                    : null);
            if (!$user) {
                return;
            }

            if ($give) {
                Role::firstOrCreate([
                    'name'       => 'Driver',
                    'guard_name' => config('auth.defaults.guard', 'web'),
                ]);
                if (!$user->hasRole('Driver')) {
                    $user->assignRole('Driver');
                }
            } elseif ($user->hasRole('Driver')) {
                $user->removeRole('Driver');
            }
        } catch (Throwable $e) {
            Log::warning('Driver role sync failed', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);
        }
    }
}
