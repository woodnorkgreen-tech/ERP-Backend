<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Models\Delivery;
use App\Modules\Logistics\Models\DeliveryStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogisticsAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $days  = (int) ($request->days ?? 30);
        $since = now()->subDays($days)->startOfDay();

        // ── Summary KPIs ──────────────────────────────────────────────────────
        $deliveries = Delivery::whereIn('status', ['delivered', 'partial'])
            ->where('completed_at', '>=', $since)
            ->get();

        $totalDeliveries  = $deliveries->count();
        $avgKm            = $deliveries->whereNotNull('total_km')->avg('total_km');
        $avgDurationMins  = $deliveries->whereNotNull('total_duration_minutes')->avg('total_duration_minutes');
        $onTimeCount      = $deliveries->where('on_time', true)->count();
        $onTimeRate       = $totalDeliveries > 0 ? round(($onTimeCount / $totalDeliveries) * 100) : null;

        $summary = [
            'total_deliveries'   => $totalDeliveries,
            'avg_km'             => $avgKm ? round($avgKm, 1) : null,
            'avg_duration_mins'  => $avgDurationMins ? (int) round($avgDurationMins) : null,
            'on_time_rate'       => $onTimeRate,
        ];

        // ── Top routes ────────────────────────────────────────────────────────
        $topRoutes = DB::table('delivery_stops as ds')
            ->join('trip_requests as tr', 'ds.trip_request_id', '=', 'tr.id')
            ->join('deliveries as d', 'ds.delivery_id', '=', 'd.id')
            ->whereIn('d.status', ['delivered', 'partial'])
            ->where('d.completed_at', '>=', $since)
            ->whereNotNull('ds.distance_from_prev_km')
            ->select(
                DB::raw("CONCAT(tr.pickup_location, ' → ', tr.destination) as route"),
                DB::raw('COUNT(*) as trips'),
                DB::raw('ROUND(AVG(ds.distance_from_prev_km), 1) as avg_km')
            )
            ->groupBy('route')
            ->orderByDesc('trips')
            ->limit(8)
            ->get()
            ->toArray();

        // ── Driver performance ────────────────────────────────────────────────
        $drivers = DB::table('deliveries as d')
            ->join('drivers as dr', 'd.driver_id', '=', 'dr.id')
            ->join('employees as e', 'dr.employee_id', '=', 'e.id')
            ->whereIn('d.status', ['delivered', 'partial'])
            ->where('d.completed_at', '>=', $since)
            ->select(
                DB::raw("CONCAT(e.first_name, ' ', e.last_name) as driver_name"),
                DB::raw('COUNT(*) as total_deliveries'),
                DB::raw('ROUND(AVG(d.total_km), 1) as avg_km'),
                DB::raw('ROUND(AVG(d.avg_speed_kmh), 1) as avg_speed'),
                DB::raw('SUM(CASE WHEN d.on_time = 1 THEN 1 ELSE 0 END) as on_time_count')
            )
            ->groupBy('driver_name')
            ->orderByDesc('total_deliveries')
            ->get()
            ->map(function ($d) {
                $d->on_time_rate = $d->total_deliveries > 0
                    ? round(($d->on_time_count / $d->total_deliveries) * 100)
                    : 0;
                return $d;
            })
            ->toArray();

        // ── Stop stats ────────────────────────────────────────────────────────
        $stops = DB::table('delivery_stops as ds')
            ->join('deliveries as d', 'ds.delivery_id', '=', 'd.id')
            ->whereIn('d.status', ['delivered', 'partial'])
            ->where('d.completed_at', '>=', $since)
            ->whereNotNull('ds.actual_duration_minutes')
            ->select(
                DB::raw('ROUND(AVG(ds.actual_duration_minutes)) as avg_duration'),
                DB::raw('COUNT(*) as total_stops'),
                DB::raw('SUM(CASE WHEN ds.arrival_delta_minutes < 0 THEN 1 ELSE 0 END) as early_count'),
                DB::raw('SUM(CASE WHEN ds.arrival_delta_minutes > 10 THEN 1 ELSE 0 END) as late_count'),
                DB::raw('SUM(CASE WHEN ds.traffic_encountered = 1 THEN 1 ELSE 0 END) as traffic_count')
            )
            ->first();

        $stopStats = null;
        if ($stops && $stops->total_stops > 0) {
            $stopStats = [
                'avg_duration' => $stops->avg_duration,
                'early_pct'    => round(($stops->early_count / $stops->total_stops) * 100),
                'late_pct'     => round(($stops->late_count  / $stops->total_stops) * 100),
                'traffic_pct'  => round(($stops->traffic_count / $stops->total_stops) * 100),
            ];
        }

        // ── Daily deliveries ──────────────────────────────────────────────────
        $daily = DB::table('deliveries')
            ->whereIn('status', ['delivered', 'partial'])
            ->where('completed_at', '>=', $since)
            ->select(
                DB::raw('DATE(completed_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        // Fill gaps so chart always has entries for every day
        $allDays = collect();
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $found = collect($daily)->firstWhere('date', $date);
            $allDays->push(['date' => substr($date, 5), 'count' => $found ? $found->count : 0]);
        }

        return response()->json([
            'data' => [
                'summary'    => $summary,
                'top_routes' => $topRoutes,
                'drivers'    => $drivers,
                'stop_stats' => $stopStats,
                'daily'      => $allDays->values(),
            ],
        ]);
    }
}
