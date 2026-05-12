<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\DispatchBatch;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Models\Delivery;
use App\Modules\Logistics\Models\DeliveryStop;
use App\Modules\Logistics\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DispatchBatchController extends Controller
{
    private array $with = [
        'driver.employee',
        'vehicle',
        'createdBy',
        'tripRequests.requestedBy',
        'tripRequests.project',
        'delivery',
    ];

    public function index(Request $request): JsonResponse
    {
        $batches = DispatchBatch::with($this->with)
            ->when($request->date,   fn($q) => $q->whereDate('dispatch_date', $request->date))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25);

        return response()->json($batches);
    }

    public function availableRequests(): JsonResponse
    {
        $requests = TripRequest::with(['requestedBy', 'project'])
            ->where('status', 'approved')
            ->whereNull('batch_id')
            ->latest()
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dispatch_date'      => 'required|date',
            'departure_time'     => 'nullable|date_format:H:i',
            'driver_id'          => 'nullable|exists:drivers,id',
            'vehicle_id'         => 'nullable|exists:vehicles,id',
            'notes'              => 'nullable|string|max:500',
            'trip_request_ids'   => 'required|array|min:1',
            'trip_request_ids.*' => 'exists:trip_requests,id',
        ]);

        $batch = null;

        DB::transaction(function () use ($validated, &$batch) {
            $batch = DispatchBatch::create([
                'dispatch_date'  => $validated['dispatch_date'],
                'departure_time' => $validated['departure_time'] ?? null,
                'driver_id'      => $validated['driver_id'] ?? null,
                'vehicle_id'     => $validated['vehicle_id'] ?? null,
                'created_by_id'  => Auth::user()->employee?->id,
                'notes'          => $validated['notes'] ?? null,
                'status'         => 'draft',
            ]);

            foreach ($validated['trip_request_ids'] as $order => $tripId) {
                TripRequest::where('id', $tripId)->update([
                    'batch_id'   => $batch->id,
                    'stop_order' => $order + 1,
                    'status'     => 'assigned',
                ]);
            }
        });

        $batch->load($this->with);
        return response()->json(['data' => $batch], 201);
    }

    public function show(DispatchBatch $dispatchBatch): JsonResponse
    {
        $dispatchBatch->load($this->with);
        return response()->json(['data' => $dispatchBatch]);
    }

    public function update(Request $request, DispatchBatch $dispatchBatch): JsonResponse
    {
        if ($dispatchBatch->status === 'confirmed') {
            return response()->json(['message' => 'Confirmed batches cannot be edited.'], 422);
        }

        $validated = $request->validate([
            'dispatch_date'      => 'sometimes|date',
            'departure_time'     => 'nullable|date_format:H:i',
            'driver_id'          => 'nullable|exists:drivers,id',
            'vehicle_id'         => 'nullable|exists:vehicles,id',
            'notes'              => 'nullable|string|max:500',
            'trip_request_ids'   => 'sometimes|array',
            'trip_request_ids.*' => 'exists:trip_requests,id',
        ]);

        DB::transaction(function () use ($validated, $dispatchBatch) {
            $dispatchBatch->update(collect($validated)->except('trip_request_ids')->toArray());

            if (isset($validated['trip_request_ids'])) {
                $oldIds   = $dispatchBatch->tripRequests->pluck('id')->toArray();
                $newIds   = $validated['trip_request_ids'];
                $toRemove = array_diff($oldIds, $newIds);

                TripRequest::whereIn('id', $toRemove)->update([
                    'batch_id'   => null,
                    'stop_order' => null,
                    'status'     => 'approved',
                ]);

                foreach ($newIds as $order => $tripId) {
                    TripRequest::where('id', $tripId)->update([
                        'batch_id'   => $dispatchBatch->id,
                        'stop_order' => $order + 1,
                        'status'     => 'assigned',
                    ]);
                }
            }
        });

        $dispatchBatch->load($this->with);
        return response()->json(['data' => $dispatchBatch]);
    }

   public function confirm(DispatchBatch $dispatchBatch): JsonResponse
{
    if ($dispatchBatch->status !== 'draft') {
        return response()->json(['message' => 'Only draft batches can be confirmed.'], 422);
    }

    if (!$dispatchBatch->driver_id || !$dispatchBatch->vehicle_id) {
        return response()->json(['message' => 'Assign a driver and vehicle before confirming.'], 422);
    }

    // ✅ ADD THIS - Check driver availability
    $driverBusy = Delivery::where('driver_id', $dispatchBatch->driver_id)
        ->whereIn('status', ['pending', 'in_transit'])
        ->exists();
    
    if ($driverBusy) {
        return response()->json(['message' => 'Driver is already on an active delivery.'], 422);
    }

    // ✅ ADD THIS - Check vehicle availability
    $vehicleBusy = Delivery::where('vehicle_id', $dispatchBatch->vehicle_id)
        ->whereIn('status', ['pending', 'in_transit'])
        ->exists();
    
    if ($vehicleBusy) {
        return response()->json(['message' => 'Vehicle is already in use on an active delivery.'], 422);
    }

    // ✅ ADD THIS - Check vehicle not in maintenance
    $vehicle = Vehicle::find($dispatchBatch->vehicle_id);
    if ($vehicle && $vehicle->status === 'maintenance') {
        return response()->json(['message' => 'Vehicle is currently under maintenance.'], 422);
    }

    $trips = $dispatchBatch->tripRequests()->with(['requestedBy', 'project'])->get();

    if ($trips->isEmpty()) {
        return response()->json(['message' => 'Batch has no trip requests.'], 422);
    }

    DB::transaction(function () use ($dispatchBatch, $trips) {
        $delivery = Delivery::create([
            'batch_id'        => $dispatchBatch->id,
            'driver_id'       => $dispatchBatch->driver_id,
            'vehicle_id'      => $dispatchBatch->vehicle_id,
            'total_stops'     => $trips->count(),
            'completed_stops' => 0,
            'status'          => 'pending',
            'delivery_date'   => $dispatchBatch->dispatch_date,
            'departure_time'  => $dispatchBatch->departure_time,
            'notes'           => $dispatchBatch->notes,
        ]);

        foreach ($trips as $trip) {
            DeliveryStop::create([
                'delivery_id'     => $delivery->id,
                'trip_request_id' => $trip->id,
                'stop_order'      => $trip->stop_order,
                'location'        => $trip->destination,
                'lat'             => $trip->destination_lat,
                'lng'             => $trip->destination_lng,
                'status'          => 'pending',
            ]);
        }

        $dispatchBatch->update([
            'status'       => 'confirmed',
            'confirmed_at' => now(),
        ]);

        Vehicle::where('id', $dispatchBatch->vehicle_id)->update(['status' => 'booked']);
    });

    $dispatchBatch->load($this->with);
    return response()->json(['data' => $dispatchBatch]);
}

    public function destroy(DispatchBatch $dispatchBatch): JsonResponse
    {
        if ($dispatchBatch->status !== 'draft') {
            return response()->json(['message' => 'Only draft batches can be deleted.'], 422);
        }

        DB::transaction(function () use ($dispatchBatch) {
            TripRequest::where('batch_id', $dispatchBatch->id)->update([
                'batch_id'   => null,
                'stop_order' => null,
                'status'     => 'approved',
            ]);
            $dispatchBatch->delete();
        });

        return response()->json(['message' => 'Batch deleted.']);
    }
}
