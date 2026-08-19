<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Modules\ClientService\Models\Client;
use App\Modules\Logistics\Models\Delivery;
use App\Constants\EnquiryConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ClientProfileController extends Controller
{
    /**
     * The only "in flight" delivery state. A delivery stays `in_transit` from
     * departure until EVERY stop is terminal; only then does it flip to a
     * terminal status (delivered / failed / `partial`=mixed) with completed_at
     * set and its GPS ping deleted (see DriverDeliveryController::recalculateDelivery).
     * So `partial` is finished, NOT in-progress, and must be excluded here.
     */
    private const ACTIVE_DELIVERY_STATUSES = ['in_transit'];

    /** Confirmed dispatches Client Service must follow before and after departure. */
    private const DELIVERY_FOLLOW_UP_STATUSES = ['pending', 'in_transit'];

    /**
     * A stop still awaiting the client — not yet completed. This is the real
     * combined-trip guard: on a shared vehicle one client's drop can already be
     * `delivered` while others remain `pending`/`en_route`, so we attribute a
     * client to a live delivery only via their own not-yet-delivered stop.
     */
    private const ACTIVE_STOP_STATUSES = ['pending', 'en_route'];

    /**
     * Client 360: profile details, project history with contracted value,
     * lifetime-spend metrics, and the most recent interaction timeline entries.
     */
    public function show($id): JsonResponse
    {
        $client = Client::with(['enquiries' => function ($q) {
            $q->orderByDesc('date_received');
        }])->findOrFail($id);

        $enquiries = $client->enquiries;

        $activeStatuses = EnquiryConstants::getActiveStatuses();
        $completedStatuses = EnquiryConstants::getCompletedStatuses();

        // Lifetime spend = sum of client-approved (contracted) quote value.
        // NOTE: the ERP does not track cash actually collected, so this is the
        // contracted value of approved projects, not payments received.
        $lifetimeSpend = (float) $enquiries
            ->whereNotNull('client_approved_quote')
            ->sum('client_approved_quote');

        $projects = $enquiries->map(function ($e) {
            return [
                'id' => $e->id,
                'title' => $e->title,
                'job_number' => $e->job_number,
                'enquiry_number' => $e->enquiry_number,
                'status' => $e->status,
                'value' => $e->client_approved_quote !== null ? (float) $e->client_approved_quote : null,
                'date_received' => $e->date_received ? $e->date_received->toDateString() : null,
                'created_at' => $e->created_at ? $e->created_at->toISOString() : null,
            ];
        })->values();

        $interactions = $client->interactions()
            ->with('user:id,name')
            ->orderByDesc('interaction_at')
            ->limit(10)
            ->get()
            ->map(fn ($i) => $this->formatInteraction($i));

        return response()->json([
            'data' => [
                'client' => $client->makeHidden('enquiries'),
                'metrics' => [
                    'lifetime_spend' => $lifetimeSpend,
                    'total_projects' => $enquiries->count(),
                    'active_projects' => $enquiries->whereIn('status', $activeStatuses)->count(),
                    'completed_projects' => $enquiries->whereIn('status', $completedStatuses)->count(),
                    'first_project_at' => optional($enquiries->min('date_received'))->toDateString(),
                    'last_project_at' => optional($enquiries->max('date_received'))->toDateString(),
                    'is_repeat_customer' => $enquiries->count() > 1,
                ],
                'projects' => $projects,
                'recent_interactions' => $interactions,
            ],
        ]);
    }

    /**
     * Clients with a confirmed delivery awaiting departure or already in transit.
     * Draft dispatch batches are intentionally excluded: they are still internal
     * planning and should not be communicated as a committed client delivery.
     */
    public function activeDeliveryClients(): JsonResponse
    {
        $clients = Delivery::whereIn('status', self::DELIVERY_FOLLOW_UP_STATUSES)
            ->whereHas('stops', fn ($q) => $q->whereIn('status', self::ACTIVE_STOP_STATUSES))
            ->with([
                // Only the still-pending stops carry a client who is genuinely awaiting.
                'stops' => fn ($q) => $q->whereIn('status', self::ACTIVE_STOP_STATUSES),
                'stops.tripRequest.project.client',
            ])
            ->get()
            ->flatMap(fn ($delivery) => $delivery->stops->map(function ($stop) use ($delivery) {
                $client = $stop->tripRequest?->project?->client;

                return $client ? compact('client', 'delivery') : null;
            }))
            ->filter()
            ->groupBy(fn ($item) => $item['client']->id)
            ->map(function ($items) {
                $client = $items->first()['client'];
                $deliveries = $items->pluck('delivery')->unique('id');
                $inTransit = $deliveries->firstWhere('status', 'in_transit');
                $nextDelivery = $deliveries->sortBy('delivery_date')->first();

                return [
                    'id' => $client->id,
                    'name' => $client->full_name ?? $client->company_name ?? 'Client',
                    'stage' => $inTransit ? 'in_transit' : 'scheduled',
                    'delivery_count' => $deliveries->count(),
                    'delivery_code' => ($inTransit ?? $nextDelivery)?->delivery_code,
                    'delivery_date' => $nextDelivery?->delivery_date?->toDateString(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['data' => $clients]);
    }

    /**
     * Live delivery tracking for a client: any in-transit delivery whose stops
     * carry this client's goods, with vehicle, driver, ETA/speed/distance and the
     * client's own stop(s). Powers the "where is my order?" panel on the profile.
     */
    public function activeDelivery($id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $deliveries = Delivery::with([
                'driver.employee',
                'vehicle',
                'latestLocation',
                'stops' => fn ($q) => $q->orderBy('stop_order'),
                'stops.tripRequest.project',
            ])
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            // Surface the delivery only if THIS client still has an undelivered stop on it
            // (on a combined trip their drop may already be done while others remain).
            ->whereHas('stops', function ($q) use ($client) {
                $q->whereIn('status', self::ACTIVE_STOP_STATUSES)
                  ->whereHas('tripRequest.project', fn ($p) => $p->where('client_id', $client->id));
            })
            ->get();

        $data = $deliveries->map(function ($delivery) use ($client) {
            $loc = $delivery->latestLocation;

            $nextStop = $delivery->stops
                ->whereIn('status', ['en_route', 'pending'])
                ->sortBy('stop_order')
                ->first();

            // Mirror the fallback chain used by gpsTracking():
            // real GPS ping → trip destination coords → trip pickup coords
            $lat = $loc?->latitude
                ?? $nextStop?->tripRequest?->destination_lat
                ?? $nextStop?->tripRequest?->pickup_lat
                ?? null;

            $lng = $loc?->longitude
                ?? $nextStop?->tripRequest?->destination_lng
                ?? $nextStop?->tripRequest?->pickup_lng
                ?? null;

            // The stop(s) on this trip that belong to the current client.
            $clientStops = $delivery->stops
                ->filter(fn ($s) => (int) optional($s->tripRequest?->project)->client_id === (int) $client->id)
                ->map(fn ($s) => [
                    'stop_order' => $s->stop_order,
                    'location' => $s->location,
                    'status' => $s->status,
                    'job_number' => $s->tripRequest?->project?->job_number,
                    'title' => $s->tripRequest?->project?->title,
                ])
                ->values();

            return [
                'delivery_id' => $delivery->id,
                'delivery_code' => $delivery->delivery_code,
                'driver_name' => $delivery->driver?->employee?->name ?? '—',
                'vehicle_plate' => $delivery->vehicle?->plate_number ?? '—',
                'vehicle_type' => $delivery->vehicle?->vehicle_type ?? '—',
                'vehicle_status' => $loc?->vehicle_status ?? 'stopped',
                'speed_kmh' => $loc ? (float) $loc->speed_kmh : 0,
                'eta_minutes' => $loc?->eta_minutes,
                'distance_to_next_stop_km' => $loc ? (float) $loc->distance_to_next_stop_km : null,
                'has_live_gps' => $loc !== null,
                'recorded_at' => $loc?->recorded_at ? $loc->recorded_at->toISOString() : null,
                'latitude' => $lat,
                'longitude' => $lng,
                'completed_stops' => $delivery->completed_stops,
                'total_stops' => $delivery->total_stops,
                'next_stop' => $nextStop ? [
                    'stop_order' => $nextStop->stop_order,
                    'location' => $nextStop->location,
                    'status' => $nextStop->status,
                ] : null,
                'client_stops' => $clientStops,
            ];
        });

        return response()->json(['data' => $data]);
    }

    private function formatInteraction($i): array
    {
        return [
            'id' => $i->id,
            'type' => $i->type,
            'subject' => $i->subject,
            'body' => $i->body,
            'enquiry_id' => $i->enquiry_id,
            'interaction_at' => $i->interaction_at ? $i->interaction_at->toISOString() : null,
            'user_name' => $i->user->name ?? 'System',
        ];
    }
}
