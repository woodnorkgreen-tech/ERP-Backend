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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DriverDeliveryController extends Controller
{
    private array $with = [
        'driver.employee',
        'vehicle',
        'batch',
        'stops.tripRequest.project',
        'stops.tripRequest.requestedBy',
    ];

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
            $deliveredAt = now();
            $arrivedAt   = $stop->arrived_at ?? $deliveredAt;

            // Calculate actual duration from when this stop became en_route
            $enRouteSince = $stop->arrived_at ?? $delivery->started_at ?? $deliveredAt;
            $actualMins   = (int) $enRouteSince->diffInMinutes($deliveredAt);

            // Delta vs scheduled ETA
            $delta = $stop->scheduled_eta_minutes
                ? $actualMins - $stop->scheduled_eta_minutes
                : null;

            $stop->update([
                'status'                  => 'delivered',
                'delivered_at'            => $deliveredAt,
                'arrived_at'              => $arrivedAt,
                'actual_duration_minutes' => $actualMins,
                'arrival_delta_minutes'   => $delta,
            ]);

            $this->recalculateDelivery($delivery);
            $delivery->stops()
                ->where('stop_order', $stop->stop_order + 1)
                ->where('status', 'pending')
                ->first()
                ?->update(['status' => 'en_route']);
        });

        $delivery->load($this->with);
        return response()->json(['message' => 'Stop marked as delivered.', 'data' => $this->formatDelivery($delivery)]);
    }

    public function failed(Request $request, Delivery $delivery, DeliveryStop $stop): JsonResponse
    {
        $this->authorizeDriver($delivery);
        $validated = $request->validate(['failure_reason' => 'required|string|max:500']);

        DB::transaction(function () use ($validated, $delivery, $stop) {
            $stop->update([
                'status'         => 'failed',
                'delivered_at'   => now(),
                'failure_reason' => $validated['failure_reason'],
            ]);
            $this->recalculateDelivery($delivery);
            $delivery->stops()
                ->where('stop_order', $stop->stop_order + 1)
                ->where('status', 'pending')
                ->first()
                ?->update(['status' => 'en_route']);
        });

        $delivery->load($this->with);
        return response()->json(['message' => 'Stop marked as failed.', 'data' => $this->formatDelivery($delivery)]);
    }

    /**
     * Called by driver app every ~10 seconds.
     * Enriches ping with Google Routes API: distance, ETA, traffic delay.
     */
    public function updateLocation(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorizeDriver($delivery);

        $validated = $request->validate([
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric',
            'speed_kmh'      => 'nullable|numeric|min:0',
            'vehicle_status' => 'nullable|in:moving,idle,stopped',
        ]);

        $lat = $validated['latitude'];
        $lng = $validated['longitude'];

        // Find next pending/en_route stop
        $nextStop = $delivery->stops()
            ->with('tripRequest')
            ->whereIn('status', ['en_route', 'pending'])
            ->orderBy('stop_order')
            ->first();

        // Enrich with Google Routes API
        $routeData = $this->fetchRouteData($lat, $lng, $nextStop);

        ActiveTripLocation::updateOrCreate(
            ['delivery_id' => $delivery->id],
            [
                'user_id'                    => Auth::id(),
                'latitude'                   => $lat,
                'longitude'                  => $lng,
                'speed_kmh'                  => $validated['speed_kmh'] ?? 0,
                'vehicle_status'             => $validated['vehicle_status'] ?? 'moving',
                'recorded_at'                => now(),
                'distance_to_next_stop_km'   => $routeData['distance_km'],
                'eta_minutes'                => $routeData['eta_minutes'],
                'traffic_delay_minutes'      => $routeData['delay_minutes'],
                'route_polyline'             => $routeData['polyline'],
                'next_stop_id'               => $nextStop?->id,
            ]
        );

        // If next stop has no scheduled ETA yet, set it now from Google's estimate
        if ($nextStop && !$nextStop->scheduled_eta_minutes && $routeData['eta_minutes']) {
            $nextStop->update([
                'scheduled_eta_minutes'  => $routeData['eta_minutes'],
                'distance_from_prev_km'  => $routeData['distance_km'],
                'traffic_encountered'    => ($routeData['delay_minutes'] ?? 0) > 5,
            ]);
        }

        return response()->json([
            'success'      => true,
            'eta_minutes'  => $routeData['eta_minutes'],
            'distance_km'  => $routeData['distance_km'],
            'delay_minutes'=> $routeData['delay_minutes'],
        ]);
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
        $inTransitDeliveries = Delivery::with([
            'driver.employee',
            'vehicle',
            'stops'                           => fn($q) => $q->orderBy('stop_order'),
            'stops.tripRequest',
            'stops.tripRequest.project',
            'stops.tripRequest.project.client',
        ])
        ->where('status', 'in_transit')
        ->get();

        $locationPings = ActiveTripLocation::whereIn(
            'delivery_id', $inTransitDeliveries->pluck('id')
        )->get()->keyBy('delivery_id');

        $locations = $inTransitDeliveries->map(function ($delivery) use ($locationPings) {
            $loc = $locationPings->get($delivery->id);

            $nextStop = $delivery->stops
                ->whereIn('status', ['en_route', 'pending'])
                ->sortBy('stop_order')
                ->first();

            // Fallback chain: live ping → trip dest → trip pickup → stop coords
            $lat = $loc?->latitude
                ?? $nextStop?->tripRequest?->destination_lat
                ?? $nextStop?->tripRequest?->pickup_lat
                ?? $nextStop?->lat
                ?? null;

            $lng = $loc?->longitude
                ?? $nextStop?->tripRequest?->destination_lng
                ?? $nextStop?->tripRequest?->pickup_lng
                ?? $nextStop?->lng
                ?? null;

            if (!$lat || !$lng) return null;

            $route = $delivery->stops
                ->sortBy('stop_order')
                ->flatMap(function ($stop) {
                    if (!$stop->tripRequest) return [];
                    $points = [];
                    if ($stop->tripRequest->pickup_lat && $stop->tripRequest->pickup_lng) {
                        $points[] = ['lat' => (float) $stop->tripRequest->pickup_lat, 'lng' => (float) $stop->tripRequest->pickup_lng];
                    }
                    if ($stop->tripRequest->destination_lat && $stop->tripRequest->destination_lng) {
                        $points[] = ['lat' => (float) $stop->tripRequest->destination_lat, 'lng' => (float) $stop->tripRequest->destination_lng];
                    }
                    return $points;
                })
                ->filter(fn($p) => $p['lat'] && $p['lng'])
                ->values()
                ->toArray();

            $stopsDetail = $delivery->stops
                ->sortBy('stop_order')
                ->map(function ($s) {
                    $tr      = $s->tripRequest;
                    $project = $tr?->project;
                    return [
                        'stop_order'              => $s->stop_order,
                        'location'                => $s->location,
                        'status'                  => $s->status,
                        'destination_lat'         => $tr?->destination_lat ?? $s->lat,
                        'destination_lng'         => $tr?->destination_lng ?? $s->lng,
                        'delivery_note'           => $s->delivery_note,
                        'distance_from_prev_km'   => $s->distance_from_prev_km,
                        'scheduled_eta_minutes'   => $s->scheduled_eta_minutes,
                        'actual_duration_minutes' => $s->actual_duration_minutes,
                        'arrival_delta_minutes'   => $s->arrival_delta_minutes,
                        'traffic_encountered'     => $s->traffic_encountered,
                        // Context: what is being carried / what this trip is for
                        'context_type'            => $tr?->context_type,
                        'delivery_type_label'     => $tr?->delivery_type_label,
                        'project'                 => $project ? [
                            'id'              => $project->id,
                            'job_number'      => $project->job_number      ?? null,
                            'enquiry_number'  => $project->enquiry_number  ?? null,
                            'title'           => $project->title           ?? null,
                            'venue'           => $project->venue           ?? null,
                            'client_id'       => $project->client?->id ?? null,
                            'client_name'     => $project->client?->full_name ?? $project->client?->name ?? null,
                        ] : null,
                    ];
                })
                ->values()
                ->toArray();

            return [
                'delivery_id'            => $delivery->id,
                'delivery_code'          => $delivery->delivery_code,
                'driver_name'            => $delivery->driver?->employee?->name ?? '—',
                'vehicle_plate'          => $delivery->vehicle?->plate_number ?? '—',
                'vehicle_type'           => $delivery->vehicle?->vehicle_type ?? '—',
                'latitude'               => $lat,
                'longitude'              => $lng,
                'speed_kmh'              => $loc?->speed_kmh ?? 0,
                'vehicle_status'         => $loc?->vehicle_status ?? 'stopped',
                'recorded_at'            => $loc?->recorded_at?->toDateTimeString() ?? $delivery->started_at?->toDateTimeString(),
                'has_live_gps'           => $loc !== null,
                'eta_minutes'            => $loc?->eta_minutes,
                'distance_to_next_stop_km' => $loc?->distance_to_next_stop_km,
                'traffic_delay_minutes'  => $loc?->traffic_delay_minutes,
                'route'                  => $route,
                'stops_detail'           => $stopsDetail,
                'next_stop'              => $nextStop ? [
                    'stop_order' => $nextStop->stop_order,
                    'location'   => $nextStop->location,
                    'lat'        => $nextStop->lat,
                    'lng'        => $nextStop->lng,
                    'status'     => $nextStop->status,
                ] : null,
                'completed_stops'        => $delivery->completed_stops,
                'total_stops'            => $delivery->total_stops,
                // Delivery-level context: first stop's trip request carries the primary context
                'delivery_type_label'    => $delivery->stops->first()?->tripRequest?->delivery_type_label,
                'context_type'           => $delivery->stops->first()?->tripRequest?->context_type,
                'project'                => $delivery->stops->first()?->tripRequest?->project ? [
                    'id'             => $delivery->stops->first()->tripRequest->project->id,
                    'job_number'     => $delivery->stops->first()->tripRequest->project->job_number      ?? null,
                    'enquiry_number' => $delivery->stops->first()->tripRequest->project->enquiry_number  ?? null,
                    'title'          => $delivery->stops->first()->tripRequest->project->title           ?? null,
                    'venue'          => $delivery->stops->first()->tripRequest->project->venue           ?? null,
                    'client_id'      => $delivery->stops->first()->tripRequest->project->client?->id ?? null,
                    'client_name'    => $delivery->stops->first()->tripRequest->project->client?->full_name
                                    ?? $delivery->stops->first()->tripRequest->project->client?->name
                                    ?? null,
                ] : null,
            ];
        })
        ->filter()
        ->values();

        return response()->json(['data' => $locations]);
    }

    /**
     * Past deliveries with route data for map replay.
     */
    public function deliveryHistory(Request $request): JsonResponse
    {
        $days   = (int) ($request->days ?? 30);
        $search = $request->search;

        $deliveries = Delivery::with([
            'driver.employee',
            'vehicle',
            'stops'                           => fn($q) => $q->orderBy('stop_order'),
            'stops.tripRequest',
            'stops.tripRequest.project',
            'stops.tripRequest.project.client',
        ])
        ->whereIn('status', ['delivered', 'partial', 'failed', 'cancelled'])
        ->where(function ($q) use ($days) {
            $q->whereNull('completed_at')
              ->orWhere('completed_at', '>=', now()->subDays($days)->startOfDay());
        })
        ->when($search, fn($q) => $q->where('delivery_code', 'like', "%{$search}%"))
        ->orderByDesc('completed_at')
        ->limit(50)
        ->get()
        ->map(function ($delivery) {
            $stops = $delivery->stops->map(function ($s) {
                $tr      = $s->tripRequest;
                $project = $tr?->project;
                return [
                    'stop_order'              => $s->stop_order,
                    'location'                => $s->location,
                    'status'                  => $s->status,
                    'destination_lat'         => $tr?->destination_lat ?? $tr?->pickup_lat ?? $s->lat,
                    'destination_lng'         => $tr?->destination_lng ?? $tr?->pickup_lng ?? $s->lng,
                    'pickup_lat'              => $tr?->pickup_lat,
                    'pickup_lng'              => $tr?->pickup_lng,
                    'distance_from_prev_km'   => $s->distance_from_prev_km,
                    'actual_duration_minutes' => $s->actual_duration_minutes,
                    'arrival_delta_minutes'   => $s->arrival_delta_minutes,
                    'traffic_encountered'     => $s->traffic_encountered,
                    'delivered_at'            => $s->delivered_at?->toDateTimeString(),
                    'context_type'            => $tr?->context_type,
                    'delivery_type_label'     => $tr?->delivery_type_label,
                    'project'                 => $project ? [
                        'id'             => $project->id,
                        'job_number'     => $project->job_number      ?? null,
                        'enquiry_number' => $project->enquiry_number  ?? null,
                        'title'          => $project->title           ?? null,
                        'venue'          => $project->venue           ?? null,
                        'client_name'    => $project->client?->full_name ?? $project->client?->name ?? null,
                    ] : null,
                ];
            })->values()->toArray();

            return [
                'id'                     => $delivery->id,
                'delivery_code'          => $delivery->delivery_code,
                'status'                 => $delivery->status,
                'delivery_date'          => $delivery->delivery_date?->format('Y-m-d'),
                'started_at'             => $delivery->started_at?->toDateTimeString(),
                'completed_at'           => $delivery->completed_at?->toDateTimeString(),
                'total_km'               => $delivery->total_km,
                'total_duration_minutes' => $delivery->total_duration_minutes,
                'avg_speed_kmh'          => $delivery->avg_speed_kmh,
                'on_time'                => $delivery->on_time,
                'total_stops'            => $delivery->total_stops,
                'completed_stops'        => $delivery->completed_stops,
                'driver'                 => [
                    'name' => $delivery->driver?->employee?->name ?? '—',
                ],
                'vehicle'                => [
                    'plate_number' => $delivery->vehicle?->plate_number ?? '—',
                    'vehicle_type' => $delivery->vehicle?->vehicle_type ?? '—',
                ],
                'delivery_type_label'    => $delivery->stops->first()?->tripRequest?->delivery_type_label,
                'context_type'           => $delivery->stops->first()?->tripRequest?->context_type,
                'project'                => $delivery->stops->first()?->tripRequest?->project ? [
                    'id'             => $delivery->stops->first()->tripRequest->project->id,
                    'job_number'     => $delivery->stops->first()->tripRequest->project->job_number      ?? null,
                    'enquiry_number' => $delivery->stops->first()->tripRequest->project->enquiry_number  ?? null,
                    'title'          => $delivery->stops->first()->tripRequest->project->title           ?? null,
                    'venue'          => $delivery->stops->first()->tripRequest->project->venue           ?? null,
                    'client_id'      => $delivery->stops->first()->tripRequest->project->client?->id ?? null,
                    'client_name'    => $delivery->stops->first()->tripRequest->project->client?->full_name
                                    ?? $delivery->stops->first()->tripRequest->project->client?->name
                                    ?? null,
                ] : null,
                'stops' => $stops,
            ];
        });

        return response()->json(['data' => $deliveries]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Call Google Routes API to get distance, ETA, traffic delay, and encoded polyline.
     */
    private function fetchRouteData(float $fromLat, float $fromLng, ?DeliveryStop $nextStop): array
    {
        $default = ['distance_km' => null, 'eta_minutes' => null, 'delay_minutes' => null, 'polyline' => null];

        if (!$nextStop) return $default;

        $destLat = $nextStop->tripRequest?->destination_lat ?? $nextStop->lat;
        $destLng = $nextStop->tripRequest?->destination_lng ?? $nextStop->lng;

        if (!$destLat || !$destLng) return $default;

        $apiKey = config('logistics.google_maps_key');
        if (!$apiKey) return $default;

        try {
            $response = Http::timeout(5)->post(
                "https://routes.googleapis.com/directions/v2:computeRoutes?key={$apiKey}",
                [
                    'origin'      => ['location' => ['latLng' => ['latitude' => $fromLat, 'longitude' => $fromLng]]],
                    'destination' => ['location' => ['latLng' => ['latitude' => (float)$destLat, 'longitude' => (float)$destLng]]],
                    'travelMode'  => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_AWARE',
                    'extraComputations' => ['TRAFFIC_ON_POLYLINE'],
                ]
            )->withHeaders(['X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters,routes.polyline,routes.travelAdvisory']);

            if ($response->successful()) {
                $route       = $response->json('routes.0');
                $durationSec = (int) filter_var($route['duration'] ?? '0s', FILTER_SANITIZE_NUMBER_INT);
                $distanceM   = (int) ($route['distanceMeters'] ?? 0);

                // staticDuration is without traffic; duration is with — difference is delay
                $staticSec   = (int) filter_var($route['staticDuration'] ?? $route['duration'] ?? '0s', FILTER_SANITIZE_NUMBER_INT);
                $delaySec    = max(0, $durationSec - $staticSec);

                return [
                    'distance_km'   => $distanceM > 0 ? round($distanceM / 1000, 3) : null,
                    'eta_minutes'   => $durationSec > 0 ? (int) ceil($durationSec / 60) : null,
                    'delay_minutes' => $delaySec > 60 ? (int) ceil($delaySec / 60) : 0,
                    'polyline'      => $route['polyline']['encodedPolyline'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Google Routes API failed: ' . $e->getMessage());
        }

        return $default;
    }

    private function findDriverForUser($user): ?Driver
    {
        if (method_exists($user, 'employee') && $user->employee) {
            $driver = Driver::where('employee_id', $user->employee->id)->first();
            if ($driver) return $driver;
        }
        $employee = Employee::where('user_id', $user->id)->first();
        if ($employee) {
            $driver = Driver::where('employee_id', $employee->id)->first();
            if ($driver) return $driver;
        }
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

            // Calculate delivery-level summary
            $totalKm   = $allStops->sum('distance_from_prev_km');
            $totalMins = $delivery->started_at
                ? (int) $delivery->started_at->diffInMinutes($completedAt)
                : null;
            $avgSpeed  = ($totalKm && $totalMins && $totalMins > 0)
                ? round(($totalKm / $totalMins) * 60, 2)
                : null;
            $onTime    = $allStops->filter(fn($s) => $s->arrival_delta_minutes !== null)
                ->every(fn($s) => $s->arrival_delta_minutes <= 10);

            $delivery->update([
                'completed_stops'        => $deliveredCnt,
                'status'                 => $status,
                'completed_at'           => $completedAt,
                'total_km'               => $totalKm ?: null,
                'total_duration_minutes' => $totalMins,
                'avg_speed_kmh'          => $avgSpeed,
                'on_time'                => $onTime,
            ]);

            if ($delivery->vehicle_id) {
                Vehicle::where('id', $delivery->vehicle_id)->update(['status' => 'active']);
                ActiveTripLocation::where('delivery_id', $delivery->id)->delete();
            }
        } else {
            $delivery->update(['completed_stops' => $deliveredCnt, 'status' => $status]);
        }
    }

    private function formatDelivery(Delivery $delivery): array
    {
        return [
            'id'                     => $delivery->id,
            'delivery_code'          => $delivery->delivery_code,
            'status'                 => $delivery->status,
            'delivery_date'          => $delivery->delivery_date?->format('Y-m-d'),
            'departure_time'         => $delivery->departure_time,
            'total_stops'            => $delivery->total_stops,
            'completed_stops'        => $delivery->completed_stops,
            'notes'                  => $delivery->notes,
            'started_at'             => $delivery->started_at?->toDateTimeString(),
            'total_km'               => $delivery->total_km,
            'total_duration_minutes' => $delivery->total_duration_minutes,
            'avg_speed_kmh'          => $delivery->avg_speed_kmh,
            'on_time'                => $delivery->on_time,
            'driver'                 => ['id' => $delivery->driver?->id, 'name' => $delivery->driver?->employee?->name],
            'vehicle'                => ['plate_number' => $delivery->vehicle?->plate_number, 'vehicle_type' => $delivery->vehicle?->vehicle_type],
            'stops'                  => $delivery->stops->map(fn($s) => [
                'id'                      => $s->id,
                'stop_order'              => $s->stop_order,
                'location'                => $s->location,
                'lat'                     => $s->lat,
                'lng'                     => $s->lng,
                'status'                  => $s->status,
                'arrived_at'              => $s->arrived_at?->toDateTimeString(),
                'delivered_at'            => $s->delivered_at?->toDateTimeString(),
                'delivery_note'           => $s->delivery_note,
                'failure_reason'          => $s->failure_reason ?? null,
                'distance_from_prev_km'   => $s->distance_from_prev_km,
                'scheduled_eta_minutes'   => $s->scheduled_eta_minutes,
                'actual_duration_minutes' => $s->actual_duration_minutes,
                'arrival_delta_minutes'   => $s->arrival_delta_minutes,
                'traffic_encountered'     => $s->traffic_encountered,
                'trip_request'            => $s->tripRequest ? [
                    'request_code'        => $s->tripRequest->request_code,
                    'delivery_type_label' => $s->tripRequest->delivery_type_label,
                    'project'             => $s->tripRequest->project ? ['project_id' => $s->tripRequest->project->project_id] : null,
                ] : null,
            ])->values()->toArray(),
        ];
    }
}