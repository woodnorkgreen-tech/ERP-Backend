<?php

namespace App\Modules\Logistics\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Ann's follow-up: "Travel Takes (min)" on the auto-calc timeline was just
 * typed from memory — this calls Google's Distance Matrix API (server-side,
 * so the key never reaches the browser/app) to get a real drive-time
 * estimate between the pickup and destination points already picked via
 * Places, which the create forms use to auto-fill that field. It's still
 * editable afterward — this only ever supplies a starting number.
 */
class TravelEstimateController extends Controller
{
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat'      => 'required|numeric|between:-90,90',
            'pickup_lng'      => 'required|numeric|between:-180,180',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
        ]);

        $key = env('GOOGLE_MAPS_SERVER_KEY');
        if (!$key) {
            return response()->json([
                'message' => 'GOOGLE_MAPS_SERVER_KEY is not set in .env — travel time cannot be estimated automatically.',
            ], 500);
        }

        $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins'      => "{$validated['pickup_lat']},{$validated['pickup_lng']}",
            'destinations' => "{$validated['destination_lat']},{$validated['destination_lng']}",
            'key'          => $key,
            // Reflects real traffic rather than free-flow speed, since the
            // whole point is a realistic departure/loading timeline.
            'departure_time' => 'now',
        ]);

        $body = $response->json();
        $element = $body['rows'][0]['elements'][0] ?? null;

        if (($body['status'] ?? null) !== 'OK' || !$element || $element['status'] !== 'OK') {
            return response()->json([
                'message' => 'Could not estimate travel time: ' . ($element['status'] ?? $body['status'] ?? 'unknown error'),
            ], 422);
        }

        // duration_in_traffic is only present when departure_time is
        // honoured for the given key/plan — fall back to plain duration.
        $duration = $element['duration_in_traffic'] ?? $element['duration'];

        return response()->json([
            'data' => [
                'duration_minutes' => (int) round($duration['value'] / 60),
                'duration_text'    => $duration['text'],
                'distance_km'      => round(($element['distance']['value'] ?? 0) / 1000, 1),
                'distance_text'    => $element['distance']['text'] ?? null,
            ],
        ]);
    }
}
