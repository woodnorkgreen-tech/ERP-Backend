<?php

namespace App\Modules\Logistics\Services;

use App\Modules\Logistics\Models\DispatchBatch;
use App\Modules\Logistics\Models\TripRequest;
use Illuminate\Support\Carbon;

/**
 * Who is free when. A vehicle or driver is only "busy" for the time window a
 * trip actually needs, so the same car can take a morning set-up and an
 * afternoon set-down. A second request is refused only when the two windows
 * overlap — and the refusal says when the vehicle is free again.
 */
class FleetAvailability
{
    /** Trip statuses that hold a vehicle / driver. */
    private const HOLDING = ['assigned', 'in_transit'];

    /** When nothing is known about the times, assume this long on the road. */
    private const DEFAULT_MINUTES = 180;

    /** @return array{0: Carbon, 1: Carbon} [start, end] */
    public static function window(TripRequest $t): array
    {
        $travel = (int) ($t->estimated_travel_minutes ?? 0);
        $onSite = (int) ($t->estimated_loading_minutes ?? 0);
        $kind   = $t->trip_kind ?? 'delivery';

        if ($t->required_delivery_at && ($t->departure_by || $t->loading_start_by)) {
            $anchor = $t->required_delivery_at->copy();
            $start  = ($t->loading_required && $kind === 'delivery' && $t->loading_start_by)
                ? $t->loading_start_by->copy()
                : ($t->departure_by ?: $t->loading_start_by)->copy();

            // delivery: there and back. collection / personnel: time on site, then back.
            $end = $kind === 'delivery'
                ? $anchor->copy()->addMinutes($travel)
                : $anchor->copy()->addMinutes($onSite + $travel);
        } else {
            $date  = $t->required_date ? $t->required_date->toDateString() : now()->toDateString();
            $clock = $t->departure_time ?: ($t->loading_time ?: '08:00');
            try {
                $start = Carbon::parse("$date $clock");
            } catch (\Throwable $e) {
                $start = Carbon::parse("$date 08:00");
            }
            $end = $start->copy()->addMinutes(self::DEFAULT_MINUTES);
        }

        // Already on the road: it holds the vehicle at least until it is done.
        if ($t->status === 'in_transit') {
            if ($t->started_at && $t->started_at->lt($start)) {
                $start = $t->started_at->copy();
            }
            $floor = now()->addMinutes(30);
            if ($end->lt($floor)) {
                $end = $floor;
            }
        }

        return [$start, $end];
    }

    /** Trips currently holding this vehicle or driver (others than $exclude). */
    private static function holders(?int $vehicleId, ?int $driverId, array $exclude = [])
    {
        $batchTripIds = [];
        if ($vehicleId || $driverId) {
            $batchIds = DispatchBatch::where('status', 'confirmed')
                ->where(function ($q) use ($vehicleId, $driverId) {
                    if ($vehicleId) $q->orWhere('vehicle_id', $vehicleId);
                    if ($driverId)  $q->orWhere('driver_id', $driverId);
                })->pluck('id');
            $batchTripIds = $batchIds->isEmpty() ? [] : TripRequest::whereIn('batch_id', $batchIds)->pluck('id')->all();
        }

        return TripRequest::whereIn('status', self::HOLDING)
            ->when($exclude, fn ($q) => $q->whereNotIn('id', $exclude))
            ->where(function ($q) use ($vehicleId, $driverId, $batchTripIds) {
                if ($vehicleId) $q->orWhere('assigned_vehicle_id', $vehicleId);
                if ($driverId)  $q->orWhere('assigned_driver_id', $driverId);
                if ($batchTripIds) $q->orWhereIn('id', $batchTripIds);
            })
            ->get();
    }

    /**
     * First booking that overlaps [$start, $end) for this vehicle/driver, or
     * null when they are free for the whole window.
     *
     * @return array{trip: TripRequest, from: Carbon, until: Carbon, who: string}|null
     */
    public static function conflict(?int $vehicleId, ?int $driverId, Carbon $start, Carbon $end, array $exclude = []): ?array
    {
        $hit = null;
        foreach (self::holders($vehicleId, $driverId, $exclude) as $other) {
            [$s, $e] = self::window($other);
            if ($s->lt($end) && $start->lt($e)) {
                $who = ($vehicleId && ($other->assigned_vehicle_id == $vehicleId)) ? 'vehicle' : 'driver';
                if (!$hit || $e->gt($hit['until'])) {
                    $hit = ['trip' => $other, 'from' => $s, 'until' => $e, 'who' => $who];
                }
            }
        }
        return $hit;
    }

    /** Every upcoming booking of one vehicle or driver, for the picker. */
    public static function bookings(?int $vehicleId, ?int $driverId, array $exclude = []): array
    {
        $rows = [];
        foreach (self::holders($vehicleId, $driverId, $exclude) as $other) {
            [$s, $e] = self::window($other);
            if ($e->lt(now())) continue;
            $rows[] = [
                'request_code' => $other->request_code,
                'from'         => $s->toIso8601String(),
                'until'        => $e->toIso8601String(),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['from'], $b['from']));
        return $rows;
    }

    /** "Vehicle is booked until 2:30 PM on TREQ-..." */
    public static function sentence(array $c, string $label): string
    {
        return "{$label} is booked until " . $c['until']->format('H:i')
            . ($c['until']->isToday() ? '' : ' on ' . $c['until']->format('d M'))
            . " ({$c['trip']->request_code}). Pick another, or a time after that.";
    }
}
