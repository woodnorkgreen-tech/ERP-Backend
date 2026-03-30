<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Delivery;
use App\Modules\Logistics\Models\DeliveryStop;
use App\Modules\Logistics\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    private array $with = [
        'driver.employee',
        'vehicle',
        'batch',
        'stops.tripRequest.requestedBy',
        'stops.tripRequest.project',
    ];

    /**
     * GET /logistics/deliveries
     * Supports ?date=YYYY-MM-DD, ?status=pending|in_transit|delivered|failed|partial
     */
    public function index(Request $request): JsonResponse
    {
        $deliveries = Delivery::with($this->with)
            ->when($request->date,   fn($q) => $q->whereDate('delivery_date', $request->date))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25);

        return response()->json($deliveries);
    }

    /**
     * GET /logistics/deliveries/:delivery
     */
    public function show(Delivery $delivery): JsonResponse
    {
        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }

    /**
     * PATCH /logistics/deliveries/:delivery/start
     * Mark delivery as in_transit.
     */
    public function start(Delivery $delivery): JsonResponse
    {
        if ($delivery->status !== 'pending') {
            return response()->json(['message' => 'Only pending deliveries can be started.'], 422);
        }

        $delivery->update(['status' => 'in_transit', 'started_at' => now()]);
        // Update first stop to en_route
        $delivery->stops()->where('stop_order', 1)->update(['status' => 'en_route']);

        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }

    /**
     * PATCH /logistics/deliveries/:delivery/stops/:stop
     * Update an individual stop status (delivered or failed).
     */
    public function updateStop(Request $request, Delivery $delivery, DeliveryStop $stop): JsonResponse
    {
        $validated = $request->validate([
            'status'        => 'required|in:en_route,delivered,failed',
            'delivery_note' => 'nullable|string|max:500',
            'receiver_name' => 'nullable|string|max:150',
        ]);

        DB::transaction(function () use ($validated, $delivery, $stop) {
            $stop->update(array_merge($validated, [
                'arrived_at'   => $validated['status'] === 'en_route' ? now() : $stop->arrived_at,
                'delivered_at' => in_array($validated['status'], ['delivered', 'failed']) ? now() : null,
            ]));

            // Recalculate delivery status
            $allStops     = $delivery->stops()->get();
            $doneCount    = $allStops->whereIn('status', ['delivered', 'failed'])->count();
            $deliveredCnt = $allStops->where('status', 'delivered')->count();
            $failedCnt    = $allStops->where('status', 'failed')->count();
            $total        = $allStops->count();

            $deliveryStatus = 'in_transit';
            $completedAt    = null;

            if ($doneCount === $total) {
                if ($failedCnt === $total)    $deliveryStatus = 'failed';
                elseif ($deliveredCnt === $total) $deliveryStatus = 'delivered';
                else                          $deliveryStatus = 'partial';
                $completedAt = now();
            }

            $delivery->update([
                'completed_stops' => $deliveredCnt,
                'status'          => $deliveryStatus,
                'completed_at'    => $completedAt,
            ]);

            // If completed, free vehicle
            if ($completedAt && $delivery->vehicle_id) {
                Vehicle::where('id', $delivery->vehicle_id)->update(['status' => 'active']);
            }

            // Progress next stop to en_route
            if ($validated['status'] === 'delivered') {
                $nextStop = $delivery->stops()
                    ->where('stop_order', $stop->stop_order + 1)
                    ->where('status', 'pending')
                    ->first();
                if ($nextStop) {
                    $nextStop->update(['status' => 'en_route']);
                }
            }
        });

        $delivery->load($this->with);
        return response()->json(['data' => $delivery]);
    }
}
