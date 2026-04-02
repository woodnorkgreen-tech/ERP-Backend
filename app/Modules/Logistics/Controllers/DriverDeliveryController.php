<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Delivery;
use App\Modules\Logistics\Models\DeliveryStop;
use App\Modules\Logistics\Models\ActiveTripLocation;
use App\Modules\Logistics\Models\Driver;
use App\Modules\Logistics\Models\Vehicle;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DriverDeliveryController extends Controller
{
    private array $with = [
        'driver.employee',
        'vehicle',
        'batch',
        'stops.tripRequest.project',
        'stops.tripRequest.requestedBy',
    ];

    /**
     * GET /logistics/driver/my-delivery
     *
     * Returns ALL deliveries for this driver — today, this week, this month.
     * The Flutter app filters by date like the Announcement screen.
     */
    public function myDelivery(): JsonResponse
    {
        $user   = Auth::user();
        $driver = $this->findDriverForUser($user);

        if (!$driver) {
            return response()->json([
                'has_delivery' => false,
                'message'      => 'No driver profile found for your account.',
                'data'         => [],
            ]);
        }

        // All deliveries, active first (in_transit → pending → others)
        $deliveries = Delivery::with($this->with)
            ->where('driver_id', $driver->id)
            ->orderByRaw("FIELD(status, 'in_transit', 'pending', 'partial', 'delivered', 'failed') ASC")
            ->latest()
            ->get()
            ->map(fn($d) => $this->formatDelivery($d));

        return response()->json([
            'has_delivery' => $deliveries->isNotEmpty(),
            'driver' => [
                'id'             => $driver->id,
                'name'           => $driver->employee?->name ?? $user->name,
                'license_number' => $driver->license_number,
            ],
            'data' => $deliveries,
        ]);
    }

    public function start(Delivery $delivery): JsonResponse
    {
        $this->authorizeDriver($delivery);

        if ($delivery->status !== 'pending') {
            return response()->json(['message' => 'Delivery already started.'], 422);
        }

        $delivery->update(['status' => 'in_transit', 'started_at' => now()]);
        $delivery->stops()->where('stop_order', 1)->update(['status' => 'en_route']);
        $delivery->load($this->with);

        return response()->json(['message' => 'Delivery started.', 'data' => $this->formatDelivery($delivery)]);
    }

    public function arrived(Delivery $delivery, DeliveryStop $stop): JsonResponse
    {
        $this->authorizeDriver($delivery);
        $stop->update(['arrived_at' => now()]);
        $delivery->load($this->with);

        return response()->json(['message' => 'Arrival recorded.', 'data' => $this->formatDelivery($delivery)]);
    }

    public function delivered(Delivery $delivery, DeliveryStop $stop): JsonResponse
    {
        $this->authorizeDriver($delivery);

        if (!in_array($stop->status, ['en_route', 'pending'])) {
            return response()->json(['message' => 'Stop already finalised.'], 422);
        }

        DB::transaction(function () use ($delivery, $stop) {
            $stop->update(['status' => 'delivered', 'delivered_at' => now(), 'arrived_at' => $stop->arrived_at ?? now()]);
            $this->recalculateDelivery($delivery);
            $delivery->stops()->where('stop_order', $stop->stop_order + 1)->where('status', 'pending')->first()?->update(['status' => 'en_route']);
        });

        $delivery->load($this->with);
        return response()->json(['message' => 'Stop marked as delivered.', 'data' => $this->formatDelivery($delivery)]);
    }

    public function failed(Request $request, Delivery $delivery, DeliveryStop $stop): JsonResponse
    {
        $this->authorizeDriver($delivery);

        $validated = $request->validate(['failure_reason' => 'required|string|max:500']);

        DB::transaction(function () use ($validated, $delivery, $stop) {
            $stop->update(['status' => 'failed', 'delivered_at' => now(), 'failure_reason' => $validated['failure_reason']]);
            $this->recalculateDelivery($delivery);
            $delivery->stops()->where('stop_order', $stop->stop_order + 1)->where('status', 'pending')->first()?->update(['status' => 'en_route']);
        });

        $delivery->load($this->with);
        return response()->json(['message' => 'Stop marked as failed.', 'data' => $this->formatDelivery($delivery)]);
    }

    public function updateLocation(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDriver($delivery);

        $validated = $request->validate([
            'latitude'       => 'required|string',
            'longitude'      => 'required|string',
            'speed_kmh'      => 'nullable|numeric|min:0',
            'vehicle_status' => 'nullable|in:moving,idle,stopped',
        ]);

        ActiveTripLocation::updateOrCreate(
            ['delivery_id' => $delivery->id],
            [
                'user_id'        => Auth::id(),
                'latitude'       => $validated['latitude'],
                'longitude'      => $validated['longitude'],
                'speed_kmh'      => $validated['speed_kmh'] ?? 0,
                'vehicle_status' => $validated['vehicle_status'] ?? 'moving',
                'recorded_at'    => now(),
            ]
        );

        return response()->json(['success' => true]);
    }

    public function activeTrips(): JsonResponse
    {
        $deliveries = Delivery::with($this->with)
            ->where('status', 'in_transit')
            ->latest('started_at')
            ->get()
            ->map(fn($d) => $this->formatDelivery($d));

        return response()->json(['data' => $deliveries]);
    }

    public function gpsTracking(): JsonResponse
    {
        $locations = ActiveTripLocation::with([
            'delivery.driver.employee',
            'delivery.vehicle',
            'delivery.stops' => fn($q) => $q->whereIn('status', ['en_route', 'pending'])->orderBy('stop_order'),
        ])
        ->whereHas('delivery', fn($q) => $q->where('status', 'in_transit'))
        ->get()
        ->map(function ($loc) {
            $delivery = $loc->delivery;
            $nextStop = $delivery->stops->first();
            return [
                'delivery_id'     => $delivery->id,
                'delivery_code'   => $delivery->delivery_code,
                'driver_name'     => $delivery->driver?->employee?->name ?? '—',
                'vehicle_plate'   => $delivery->vehicle?->plate_number ?? '—',
                'vehicle_type'    => $delivery->vehicle?->vehicle_type ?? '—',
                'latitude'        => $loc->latitude,
                'longitude'       => $loc->longitude,
                'speed_kmh'       => $loc->speed_kmh,
                'vehicle_status'  => $loc->vehicle_status,
                'recorded_at'     => $loc->recorded_at?->toDateTimeString(),
                'next_stop'       => $nextStop ? ['stop_order' => $nextStop->stop_order, 'location' => $nextStop->location, 'lat' => $nextStop->lat, 'lng' => $nextStop->lng, 'status' => $nextStop->status] : null,
                'completed_stops' => $delivery->completed_stops,
                'total_stops'     => $delivery->total_stops,
            ];
        });

        return response()->json(['data' => $locations]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function findDriverForUser($user): ?Driver
    {
        // Strategy 1: user → employee relationship (if exists)
        if (method_exists($user, 'employee') && $user->employee) {
            $driver = Driver::where('employee_id', $user->employee->id)->first();
            if ($driver) return $driver;
        }

        // Strategy 2: employee has user_id column
        $employee = Employee::where('user_id', $user->id)->first();
        if ($employee) {
            $driver = Driver::where('employee_id', $employee->id)->first();
            if ($driver) return $driver;
        }

        // Strategy 3: match employee by email (most reliable fallback)
        $employee = Employee::where('email', $user->email)->first();
        if ($employee) {
            $driver = Driver::where('employee_id', $employee->id)->first();
            if ($driver) return $driver;
        }

        return null;
    }

    private function authorizeDriver(Delivery $delivery): void
    {
        $driver = $this->findDriverForUser(Auth::user());
        if (!$driver || $delivery->driver_id !== $driver->id) {
            abort(403, 'This delivery is not assigned to you.');
        }
    }

    private function recalculateDelivery(Delivery $delivery): void
    {
        $allStops     = $delivery->stops()->get();
        $total        = $allStops->count();
        $deliveredCnt = $allStops->where('status', 'delivered')->count();
        $failedCnt    = $allStops->where('status', 'failed')->count();
        $doneCount    = $deliveredCnt + $failedCnt;

        $status      = 'in_transit';
        $completedAt = null;

        if ($doneCount === $total) {
            $status      = $failedCnt === $total ? 'failed' : ($deliveredCnt === $total ? 'delivered' : 'partial');
            $completedAt = now();
        }

        $delivery->update(['completed_stops' => $deliveredCnt, 'status' => $status, 'completed_at' => $completedAt]);

        if ($completedAt && $delivery->vehicle_id) {
            Vehicle::where('id', $delivery->vehicle_id)->update(['status' => 'active']);
            ActiveTripLocation::where('delivery_id', $delivery->id)->delete();
        }
    }

    private function formatDelivery(Delivery $delivery): array
    {
        return [
            'id'              => $delivery->id,
            'delivery_code'   => $delivery->delivery_code,
            'status'          => $delivery->status,
            'delivery_date'   => $delivery->delivery_date?->format('Y-m-d'),
            'departure_time'  => $delivery->departure_time,
            'total_stops'     => $delivery->total_stops,
            'completed_stops' => $delivery->completed_stops,
            'notes'           => $delivery->notes,
            'started_at'      => $delivery->started_at?->toDateTimeString(),
            'driver'          => ['id' => $delivery->driver?->id, 'name' => $delivery->driver?->employee?->name],
            'vehicle'         => ['plate_number' => $delivery->vehicle?->plate_number, 'vehicle_type' => $delivery->vehicle?->vehicle_type],
            'stops'           => $delivery->stops->map(fn($s) => [
                'id'             => $s->id,
                'stop_order'     => $s->stop_order,
                'location'       => $s->location,
                'lat'            => $s->lat,
                'lng'            => $s->lng,
                'status'         => $s->status,
                'arrived_at'     => $s->arrived_at?->toDateTimeString(),
                'delivered_at'   => $s->delivered_at?->toDateTimeString(),
                'delivery_note'  => $s->delivery_note,
                'failure_reason' => $s->failure_reason ?? null,
                'trip_request'   => $s->tripRequest ? [
                    'request_code'        => $s->tripRequest->request_code,
                    'delivery_type_label' => $s->tripRequest->delivery_type_label,
                    'project'             => $s->tripRequest->project ? ['project_id' => $s->tripRequest->project->project_id] : null,
                ] : null,
            ])->values()->toArray(),
        ];
    }
}
