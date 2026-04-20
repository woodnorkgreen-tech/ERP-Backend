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

    public function index(Request $request): JsonResponse
    {
        $deliveries = Delivery::with($this->with)
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
