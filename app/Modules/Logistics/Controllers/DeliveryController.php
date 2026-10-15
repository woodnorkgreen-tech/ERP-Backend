<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Delivery;
use App\Modules\Logistics\Models\DeliveryStop;
use App\Modules\Logistics\Models\Vehicle;
use App\Modules\Logistics\Models\TripRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DeliveryController extends Controller
{
    private array $with = [
        'driver.employee',
        'vehicle',
        'batch',
        'stops.tripRequest.requestedBy',
        'stops.tripRequest.project',
        'stops.tripRequest.project.client',
    ];

    public function index(Request $request): JsonResponse
    {
        $isClientService = Auth::user()?->hasRole('Client Service') ?? false;

        $deliveries = Delivery::with($this->with)
            // Client Service needs the client-facing delivery register, not
            // unrelated internal transport movements.
            ->when($isClientService, fn ($q) => $q->whereHas(
                'stops.tripRequest.project',
                fn ($project) => $project->whereNotNull('client_id')
            ))
            ->when($request->date,   fn($q) => $q->whereDate('delivery_date', $request->date))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25);

        return response()->json($deliveries);
    }

    public function show(Delivery $delivery): JsonResponse
    {
        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }

    public function start(Delivery $delivery): JsonResponse
    {
        if ($delivery->status !== 'pending') {
            return response()->json(['message' => 'Only pending deliveries can be started.'], 422);
        }

        $delivery->update(['status' => 'in_transit', 'started_at' => now()]);

        // Mark first stop as en_route
        $delivery->stops()->where('stop_order', 1)->update(['status' => 'en_route']);
        $delivery->markTripRequestsStarted();

        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }

    public function cancel(Request $request, Delivery $delivery): JsonResponse
    {
        if ($delivery->status !== 'pending') {
            return response()->json(['message' => 'Only pending deliveries can be cancelled.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'note'   => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($validated, $delivery) {
            // Free the vehicle back to active
            if ($delivery->vehicle_id) {
                Vehicle::where('id', $delivery->vehicle_id)->update(['status' => 'active']);
            }

            // Void all pending/en_route stops
            $delivery->stops()->whereIn('status', ['pending', 'en_route'])->update(['status' => 'failed']);

            $delivery->update([
                'status'       => 'cancelled',
                'completed_at' => now(),
                'notes'        => trim(($delivery->notes ? $delivery->notes . "\n" : '')
                                  . 'Cancelled: ' . $validated['reason']
                                  . ($validated['note'] ? ' — ' . $validated['note'] : '')),
            ]);
        });

        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }
    public function edit(Request $request, Delivery $delivery): JsonResponse
{
    if ($delivery->status !== 'pending') {
        return response()->json(['message' => 'Only pending deliveries can be edited.'], 422);
    }
 
    $validated = $request->validate([
        'driver_id'               => ['sometimes', 'nullable', 'integer', 'exists:drivers,id'],
        'vehicle_id'              => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
        'departure_time'          => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        'notes'                   => ['sometimes', 'nullable', 'string', 'max:1000'],
        'new_stop'                => ['sometimes', 'nullable', 'array'],
        'new_stop.location'       => ['required_with:new_stop', 'string', 'max:500'],
        'new_stop.receiver_name'  => ['sometimes', 'nullable', 'string', 'max:200'],
        'new_stop.receiver_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        'new_stop.delivery_note'  => ['sometimes', 'nullable', 'string', 'max:500'],
    ]);
 
    DB::transaction(function () use ($delivery, $validated) {
 
        // ── Update core delivery fields ────────────────────────────────────
        $updates = [];
        foreach (['driver_id', 'vehicle_id', 'departure_time', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }
        if (!empty($updates)) {
            $delivery->update($updates);
        }
 
        // ── Sync driver / vehicle / departure_time back to the batch ──────
        if ($delivery->batch_id && !empty($updates)) {
            $batchUpdates = array_intersect_key(
                $updates,
                array_flip(['driver_id', 'vehicle_id', 'departure_time'])
            );
            if (!empty($batchUpdates)) {
                $delivery->batch()->update($batchUpdates);
            }
        }
 
        // ── Append a new stop at the end ──────────────────────────────────
        if (!empty($validated['new_stop']['location'])) {
            $nextOrder = $delivery->stops()->max('stop_order') + 1;
 
            DeliveryStop::create([
                'delivery_id'    => $delivery->id,
                'stop_order'     => $nextOrder,
                'location'       => $validated['new_stop']['location'],
                'receiver_name'  => $validated['new_stop']['receiver_name']  ?? null,
                'receiver_phone' => $validated['new_stop']['receiver_phone'] ?? null,
                'delivery_note'  => $validated['new_stop']['delivery_note']  ?? null,
                'status'         => 'pending',
            ]);
 
            $delivery->update([
                'total_stops' => $delivery->stops()->count(),
            ]);
        }
    });
 
    $delivery->load($this->with);
 
    return response()->json([
        'data'    => $delivery,
        'message' => 'Delivery updated successfully.',
    ]);
}

    /**
     * Put an approved request on a delivery that is already planned or on
     * the road — e.g. a pick-up that came in unscheduled and sits on the
     * vehicle's route. It becomes another stop, right after the one the
     * driver is heading to ('next') or at the very end ('end'), and takes
     * this delivery's driver and vehicle.
     */
    public function addRequest(Request $request, Delivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'trip_request_id' => 'required|integer|exists:trip_requests,id',
            'position'        => 'sometimes|in:next,end',
        ]);

        if (!in_array($delivery->status, ['pending', 'in_transit'])) {
            return response()->json(['message' => 'Requests can only be added to a pending or in-transit delivery.'], 422);
        }
        if (!$delivery->driver_id || !$delivery->vehicle_id) {
            return response()->json(['message' => 'This delivery has no driver and vehicle yet.'], 422);
        }

        $trip = TripRequest::find($validated['trip_request_id']);
        if ($trip->status !== 'approved') {
            return response()->json(['message' => 'Only approved requests that are not yet assigned can be added.'], 422);
        }
        if ($trip->transport_arrangement === 'client') {
            return response()->json(['message' => 'A client-collected request has no vehicle to add to.'], 422);
        }

        DB::transaction(function () use ($delivery, $trip, $validated) {
            $inTransit = $delivery->status === 'in_transit';
            $position  = $validated['position'] ?? 'next';
            $stops     = $delivery->stops()->orderBy('stop_order')->get();

            if ($position === 'end' || $stops->isEmpty()) {
                $order = ($stops->max('stop_order') ?? 0) + 1;
            } else {
                // 'next' = straight after the stop being driven to now;
                // on a delivery not yet started, the very first stop.
                $current = $stops->firstWhere('status', 'en_route')
                    ?? $stops->first(fn ($st) => $st->status === 'pending');
                $order = $inTransit && $current ? $current->stop_order + 1 : ($current?->stop_order ?? 1);
            }

            $delivery->stops()->where('stop_order', '>=', $order)->increment('stop_order');

            // Nothing in front of it still to do? Then it is the one being driven to.
            $someoneActive = $delivery->stops()->where('status', 'en_route')->exists();
            $status = ($inTransit && !$someoneActive) ? 'en_route' : 'pending';

            DeliveryStop::create([
                'delivery_id'     => $delivery->id,
                'trip_request_id' => $trip->id,
                'stop_order'      => $order,
                'location'        => $trip->destination,
                'lat'             => $trip->destination_lat,
                'lng'             => $trip->destination_lng,
                'status'          => $status,
            ]);

            $delivery->update(['total_stops' => $delivery->stops()->count()]);

            $trip->update([
                'status'              => $inTransit ? 'in_transit' : 'assigned',
                'started_at'          => $inTransit ? now() : null,
                'assigned_driver_id'  => $delivery->driver_id,
                'assigned_vehicle_id' => $delivery->vehicle_id,
                'assigned_by_id'      => Auth::user()->employee?->id,
                'assigned_at'         => now(),
                'assignment_notes'    => 'Added to delivery ' . ($delivery->delivery_code ?? '#' . $delivery->id),
            ]);
        });

        $delivery->load($this->with);
        return response()->json(['data' => $delivery, 'message' => 'Request added to the delivery.']);
    }

    public function updateStop(Request $request, Delivery $delivery, DeliveryStop $stop): JsonResponse
    {
        $validated = $request->validate([
            'status'        => 'required|in:en_route,delivered,failed',
            'delivery_note' => 'nullable|string|max:500',
            'receiver_name' => 'nullable|string|max:150',
        ]);

        DB::transaction(function () use ($validated, $delivery, $stop) {
            $stop->update([
                'status'        => $validated['status'],
                'delivery_note' => $validated['delivery_note'] ?? $stop->delivery_note,
                'receiver_name' => $validated['receiver_name'] ?? $stop->receiver_name,
                'arrived_at'    => $validated['status'] === 'en_route' ? now() : $stop->arrived_at,
                'delivered_at'  => in_array($validated['status'], ['delivered', 'failed']) ? now() : null,
            ]);

            // Recalculate delivery status from all stops
            $allStops     = $delivery->stops()->get();
            $total        = $allStops->count();
            $doneCount    = $allStops->whereIn('status', ['delivered', 'failed'])->count();
            $deliveredCnt = $allStops->where('status', 'delivered')->count();
            $failedCnt    = $allStops->where('status', 'failed')->count();

            $deliveryStatus = 'in_transit';
            $completedAt    = null;

            if ($doneCount === $total) {
                if ($failedCnt === $total)        $deliveryStatus = 'failed';
                elseif ($deliveredCnt === $total) $deliveryStatus = 'delivered';
                else                              $deliveryStatus = 'partial';
                $completedAt = now();
            }

            $delivery->update([
                'completed_stops' => $deliveredCnt,
                'status'          => $deliveryStatus,
                'completed_at'    => $completedAt,
            ]);

            // Free vehicle when delivery is done
            if ($completedAt && $delivery->vehicle_id) {
                Vehicle::where('id', $delivery->vehicle_id)->update(['status' => 'active']);
            }

            // Auto-advance next stop to en_route
            if ($validated['status'] === 'delivered') {
                $delivery->stops()
                    ->where('stop_order', $stop->stop_order + 1)
                    ->where('status', 'pending')
                    ->first()
                    ?->update(['status' => 'en_route']);
            }
        });

        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }
}
