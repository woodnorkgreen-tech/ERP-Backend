<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Http\Resources\EventResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    /**
     * Get all events visible to the authenticated user
     * Returns public events + user's personal events
     */
    public function index()
    {
        try {
            $events = Event::visibleTo(Auth::id())
                ->with('user:id,name')
                ->orderBy('start_time')
                ->get();

            return response()->json([
                'success' => true,
                'data' => EventResource::collection($events)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch events',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save a new event
     */
    public function save(Request $request)
    {
        try {
            $validated = $request->validate([
                'event_name' => 'required|string|max:255',
                'start_time' => 'required|date',
                'end_time' => 'required|date|after:start_time',
                'color' => 'required|string',
                'is_all_day' => 'nullable|boolean',
                'notes' => 'nullable|string',
                'is_public' => 'nullable|boolean',
            ]);

            $user = Auth::user();
            
            // Only HR can create public events
            if (($validated['is_public'] ?? false) && $user->department_id != 4) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only HR can create public events'
                ], 403);
            }

            DB::beginTransaction();

            $event = Event::create([
                'user_id' => $user->id,
                'event_name' => $validated['event_name'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'color' => $validated['color'],
                'is_all_day' => $validated['is_all_day'] ?? false,
                'notes' => $validated['notes'] ?? '',
                'is_public' => $validated['is_public'] ?? false,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Event created successfully',
                'data' => new EventResource($event->load('user'))
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Failed to create event',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing event
     */
    public function update(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|exists:events,id'
            ]);

            $event = Event::findOrFail($request->id);
            $user = Auth::user();

            // Check permissions
            if (!$event->canBeEditedBy($user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to edit this event'
                ], 403);
            }

            $validated = $request->validate([
                'event_name' => 'sometimes|required|string|max:255',
                'start_time' => 'sometimes|required|date',
                'end_time' => 'sometimes|required|date|after:start_time',
                'color' => 'sometimes|required|string',
                'is_all_day' => 'nullable|boolean',
                'notes' => 'nullable|string',
                'is_public' => 'nullable|boolean',
            ]);

            // Only HR can change event to public
            if (isset($validated['is_public']) && $validated['is_public'] && $user->department_id != 4) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only HR can create public events'
                ], 403);
            }

            DB::beginTransaction();

            $event->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Event updated successfully',
                'data' => new EventResource($event->load('user'))
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Failed to update event',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an event
     */
    public function delete(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|exists:events,id'
            ]);

            $event = Event::findOrFail($request->id);
            $user = Auth::user();

            // Check permissions
            if (!$event->canBeDeletedBy($user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to delete this event'
                ], 403);
            }

            DB::beginTransaction();

            $event->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Event deleted successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete event',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single event by ID
     */
    public function show($id)
    {
        try {
            $event = Event::with('user:id,name')->findOrFail($id);
            $user = Auth::user();

            // Check if user can view this event
            if (!$event->is_public && $event->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to view this event'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new EventResource($event)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Event not found',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get events by date range
     */
    public function getByDateRange(Request $request)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $events = Event::visibleTo(Auth::id())
                ->whereBetween('start_time', [$validated['start_date'], $validated['end_date']])
                ->with('user:id,name')
                ->orderBy('start_time')
                ->get();

            return response()->json([
                'success' => true,
                'data' => EventResource::collection($events)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch events',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}