<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Delivery;
use App\Modules\Logistics\Models\Driver;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Models\Vehicle;
use App\Modules\Logistics\Services\FleetAvailability;
use Illuminate\Http\JsonResponse;

/**
 * One call for the Logistics Dashboard: what is happening today, who is free,
 * and what needs attention. Everything is computed from the data the module
 * already keeps, nothing new is stored.
 */
class LogisticsDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today    = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        // ── Today's trips ────────────────────────────────────────────────
        $todayTrips = TripRequest::with(['project.client', 'assignedVehicle', 'assignedDriver.employee', 'requestedBy'])
            ->whereDate('required_date', $today)
            ->whereNotIn('status', ['rejected'])
            ->get();

        $byStatus = [];
        foreach (['requested', 'approved', 'assigned', 'in_transit', 'completed', 'cancelled'] as $st) {
            $byStatus[$st] = $todayTrips->where('status', $st)->count();
        }

        $live = $todayTrips->whereNotIn('status', ['cancelled', 'completed']);
        $kinds = [
            'delivery'   => $live->filter(fn ($t) => ($t->trip_kind ?? 'delivery') === 'delivery' && $t->transport_arrangement !== 'external')->count(),
            'collection' => $live->where('trip_kind', 'collection')->count(),
            'personnel'  => $live->where('trip_kind', 'personnel')->count(),
            'external'   => $live->where('transport_arrangement', 'external')->count(),
        ];

        // ── Fleet right now ──────────────────────────────────────────────
        $now = now();
        $vehicles = Vehicle::orderBy('plate_number')->get()->map(function ($v) use ($now) {
            $state = 'free';
            $until = null;
            $trip  = null;
            $next  = null;

            if ($v->status === 'maintenance') {
                $state = 'maintenance';
            } else {
                foreach (FleetAvailability::bookings($v->id, null) as $b) {
                    $from  = \Illuminate\Support\Carbon::parse($b['from']);
                    $until2 = \Illuminate\Support\Carbon::parse($b['until']);
                    if ($from->lte($now) && $until2->gte($now)) {
                        $state = 'busy'; $until = $b['until']; $trip = $b['request_code'];
                    } elseif ($from->gt($now) && !$next) {
                        $next = ['request_code' => $b['request_code'], 'from' => $b['from']];
                    }
                }
            }

            return [
                'id'           => $v->id,
                'plate_number' => $v->plate_number,
                'vehicle_type' => $v->vehicle_type,
                'state'        => $state,
                'busy_until'   => $until,
                'on_trip'      => $trip,
                'next_booking' => $next,
            ];
        })->values();

        $fleet = [
            'total'       => $vehicles->count(),
            'busy'        => $vehicles->where('state', 'busy')->count(),
            'free'        => $vehicles->where('state', 'free')->count(),
            'maintenance' => $vehicles->where('state', 'maintenance')->count(),
        ];

        $drivers = Driver::with('employee')->where('status', 'active')->get()->map(function ($d) use ($now) {
            $state = 'free';
            $until = null;
            foreach (FleetAvailability::bookings(null, $d->id) as $b) {
                $from = \Illuminate\Support\Carbon::parse($b['from']);
                if ($from->lte($now) && \Illuminate\Support\Carbon::parse($b['until'])->gte($now)) {
                    $state = 'busy'; $until = $b['until'];
                }
            }
            return [
                'id'         => $d->id,
                'name'       => $d->employee?->name ?? $d->employee?->full_name ?? 'Driver',
                'state'      => $state,
                'busy_until' => $until,
            ];
        })->values();

        // ── Needs attention ──────────────────────────────────────────────
        $attention = [];

        foreach ($todayTrips as $t) {
            $ts = $t->timeline_status;
            $label = $t->request_code . ' — ' . ($t->project->title ?? $t->delivery_type_label);
            if ($ts === 'overdue_loading') {
                $attention[] = ['level' => 'red', 'text' => "Loading overdue: $label", 'trip_id' => $t->id];
            } elseif ($ts === 'overdue_departure') {
                $attention[] = ['level' => 'red', 'text' => "Departure overdue: $label", 'trip_id' => $t->id];
            } elseif ($ts === 'due_soon') {
                $attention[] = ['level' => 'amber', 'text' => "Loading starts soon: $label", 'trip_id' => $t->id];
            }
        }

        $unassigned = TripRequest::where('status', 'approved')
            ->whereDate('required_date', '<=', $tomorrow)
            ->where(fn ($q) => $q->whereNull('transport_arrangement')->orWhere('transport_arrangement', '!=', 'client'))
            ->get();
        foreach ($unassigned as $t) {
            $attention[] = ['level' => 'amber', 'text' => "Approved, no vehicle yet: {$t->request_code} ({$t->required_date?->format('d M')})", 'trip_id' => $t->id];
        }

        $pending = TripRequest::where('status', 'requested')->count();

        try {
            $register = app(FleetRegisterController::class)->index()->getData(true)['data'] ?? [];
            foreach ($register as $r) {
                if (($r['service_alert'] ?? '') === 'overdue') {
                    $attention[] = ['level' => 'red', 'text' => "Service overdue: {$r['plate_number']}", 'vehicle_id' => $r['id']];
                } elseif (($r['service_alert'] ?? '') === 'due_soon') {
                    $attention[] = ['level' => 'amber', 'text' => "Service due soon: {$r['plate_number']}", 'vehicle_id' => $r['id']];
                }
            }
        } catch (\Throwable $e) {
            // Fleet register is optional here — never break the dashboard over it.
        }

        usort($attention, fn ($a, $b) => strcmp($a['level'] === 'red' ? '0' : '1', $b['level'] === 'red' ? '0' : '1'));

        // ── Coming up: today's live trips in time order ──────────────────
        $upcoming = $live->sortBy(function ($t) {
            return ($t->departure_by ?? $t->loading_start_by ?? $t->required_delivery_at ?? $t->required_date)?->timestamp ?? 0;
        })->take(10)->map(function ($t) {
            $driver = $t->assignedDriver?->employee?->name ?? $t->assignedDriver?->employee?->full_name;
            return [
                'id'           => $t->id,
                'request_code' => $t->request_code,
                'kind'         => $t->transport_arrangement === 'external' ? 'external' : ($t->trip_kind ?? 'delivery'),
                'title'        => $t->project->title ?? $t->delivery_type_label,
                'client'       => $t->project?->client?->full_name ?? $t->project?->client?->name,
                'leave_at'     => $t->departure_by,
                'needed_at'    => $t->required_delivery_at,
                'status'       => $t->status,
                'vehicle'      => $t->transport_arrangement === 'external'
                    ? ($t->external_provider ?? 'Ride')
                    : $t->assignedVehicle?->plate_number,
                'driver'       => $driver,
            ];
        })->values();

        // ── On the road now ──────────────────────────────────────────────
        $active = Delivery::with(['driver.employee', 'vehicle', 'stops'])
            ->where('status', 'in_transit')->get()->map(function ($d) {
                $next = $d->stops->sortBy('stop_order')->whereIn('status', ['en_route', 'pending'])->first();
                return [
                    'id'         => $d->id,
                    'plate'      => $d->vehicle?->plate_number,
                    'driver'     => $d->driver?->employee?->name ?? $d->driver?->employee?->full_name,
                    'stops_done' => $d->stops->whereIn('status', ['delivered', 'failed'])->count(),
                    'stops_total'=> $d->stops->count(),
                    'next_stop'  => $next?->location,
                ];
            })->values();

        // ── Next 7 days at a glance ──────────────────────────────────────
        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $day = now()->addDays($i);
            $week[] = [
                'date'  => $day->toDateString(),
                'label' => $day->format('D d'),
                'count' => TripRequest::whereDate('required_date', $day->toDateString())
                    ->whereNotIn('status', ['cancelled', 'rejected'])->count(),
            ];
        }

        return response()->json(['data' => [
            'generated_at'      => now()->toIso8601String(),
            'today'             => ['total' => $todayTrips->count(), 'by_status' => $byStatus, 'kinds' => $kinds],
            'pending_approvals' => $pending,
            'fleet'             => $fleet,
            'vehicles'          => $vehicles,
            'drivers'           => $drivers,
            'attention'         => array_slice($attention, 0, 12),
            'upcoming'          => $upcoming,
            'on_the_road'       => $active,
            'week'              => $week,
        ]]);
    }
}
