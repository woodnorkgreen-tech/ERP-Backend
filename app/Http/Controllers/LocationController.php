<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index()
    {
        $locations = Location::with('user')->latest()->get();
        return response()->json($locations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'time' => 'required|date',
        ]);

        $location = Location::create($validated);

        return response()->json([
            'message' => 'Location submitted successfully',
            'data' => $location
        ], 201);
    }

    public function show($id)
    {
        $location = Location::with('user')->findOrFail($id);
        return response()->json($location);
    }

    public function update(Request $request, $id)
    {
        $location = Location::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'location' => 'sometimes|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'time' => 'sometimes|date',
        ]);

        $location->update($validated);

        return response()->json([
            'message' => 'Location updated successfully',
            'data' => $location
        ]);
    }

    public function destroy($id)
    {
        $location = Location::findOrFail($id);
        $location->delete();

        return response()->json([
            'message' => 'Location deleted successfully'
        ]);
    }
}