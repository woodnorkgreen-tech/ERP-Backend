<?php

namespace App\Http\Controllers;

use App\Models\LogisticsLogEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class LogisticsLogController extends Controller
{
    /**
     * Get all logistics log entries with pagination and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = LogisticsLogEntry::with(['projectEnquiry', 'creator'])
                ->orderBy('created_at', 'desc');

            // Apply pagination
            $perPage = $request->get('per_page', 15);
            $entries = $query->paginate($perPage);

            // Transform the data for frontend
            $transformedEntries = $entries->getCollection()->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'project_id' => $entry->project_enquiry_id,
                    'site' => $entry->site,
                    'loading_time' => $entry->loading_time?->toISOString(),
                    'departure' => $entry->departure?->toISOString(),
                    'vehicle_allocated' => $entry->vehicle_allocated,
                    'project_officer_incharge' => $entry->project_officer_incharge,
                    'remarks' => $entry->remarks,
                    'status' => $entry->status,
                    'created_at' => $entry->created_at?->toISOString(),
                    'project_name' => $entry->projectEnquiry?->title ?? 'Unknown Project',
                    'creator_name' => $entry->creator?->name ?? 'Unknown User',
                ];
            });

            $entries->setCollection($transformedEntries);

            return response()->json([
                'message' => 'Logistics log entries retrieved successfully',
                'data' => $entries
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve logistics log entries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new logistics log entry.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'project_id' => 'required|exists:project_enquiries,id',
                'site' => 'required|string|max:255',
                'loading_time' => 'required|date',
                'departure' => 'required|date',
                'vehicle_allocated' => 'required|string|max:255',
                'project_officer_incharge' => 'required|string|max:255',
                'remarks' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $entry = LogisticsLogEntry::create([
                'project_enquiry_id' => $request->project_id,
                'site' => $request->site,
                'loading_time' => $request->loading_time,
                'departure' => $request->departure,
                'vehicle_allocated' => $request->vehicle_allocated,
                'project_officer_incharge' => $request->project_officer_incharge,
                'remarks' => $request->remarks,
                'created_by' => auth()->id(),
            ]);

            // Load relationships for response
            $entry->load(['projectEnquiry', 'creator']);

            return response()->json([
                'message' => 'Logistics log entry created successfully',
                'data' => [
                    'id' => $entry->id,
                    'project_id' => $entry->project_enquiry_id,
                    'site' => $entry->site,
                    'loading_time' => $entry->loading_time?->toISOString(),
                    'departure' => $entry->departure?->toISOString(),
                    'vehicle_allocated' => $entry->vehicle_allocated,
                    'project_officer_incharge' => $entry->project_officer_incharge,
                    'remarks' => $entry->remarks,
                    'status' => $entry->status,
                    'created_at' => $entry->created_at?->toISOString(),
                    'project_name' => $entry->projectEnquiry?->title ?? 'Unknown Project',
                    'creator_name' => $entry->creator?->name ?? 'Unknown User',
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create logistics log entry',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific logistics log entry.
     */
    public function show($id): JsonResponse
    {
        try {
            $logisticsLogEntry = LogisticsLogEntry::findOrFail($id);
            $logisticsLogEntry->load(['projectEnquiry', 'creator']);

            return response()->json([
                'message' => 'Logistics log entry retrieved successfully',
                'data' => [
                    'id' => $logisticsLogEntry->id,
                    'project_id' => $logisticsLogEntry->project_enquiry_id,
                    'site' => $logisticsLogEntry->site,
                    'loading_time' => $logisticsLogEntry->loading_time?->toISOString(),
                    'departure' => $logisticsLogEntry->departure?->toISOString(),
                    'vehicle_allocated' => $logisticsLogEntry->vehicle_allocated,
                    'project_officer_incharge' => $logisticsLogEntry->project_officer_incharge,
                    'remarks' => $logisticsLogEntry->remarks,
                    'status' => $logisticsLogEntry->status,
                    'created_at' => $logisticsLogEntry->created_at?->toISOString(),
                    'project_name' => $logisticsLogEntry->projectEnquiry?->title ?? 'Unknown Project',
                    'creator_name' => $logisticsLogEntry->creator?->name ?? 'Unknown User',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve logistics log entry',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a logistics log entry.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $logisticsLogEntry = LogisticsLogEntry::findOrFail($id);

            \Log::info('LogisticsLogController update called', [
                'entry_id' => $logisticsLogEntry->id,
                'request_data' => $request->all(),
                'current_status' => $logisticsLogEntry->status
            ]);

            $validator = Validator::make($request->all(), [
                'project_id' => 'sometimes|exists:project_enquiries,id',
                'site' => 'sometimes|string|max:255',
                'loading_time' => 'sometimes|date',
                'departure' => 'sometimes|date',
                'vehicle_allocated' => 'sometimes|string|max:255',
                'project_officer_incharge' => 'sometimes|string|max:255',
                'remarks' => 'nullable|string',
                'status' => 'sometimes|in:open,completed,closed',
            ]);

            if ($validator->fails()) {
                \Log::error('LogisticsLogController validation failed', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all()
                ]);
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $request->only([
                'project_enquiry_id',
                'site',
                'loading_time',
                'departure',
                'vehicle_allocated',
                'project_officer_incharge',
                'remarks',
                'status',
            ]);

            \Log::info('Updating logistics entry', [
                'entry_id' => $logisticsLogEntry->id,
                'update_data' => $updateData
            ]);

            $logisticsLogEntry->update($updateData);

            \Log::info('Logistics entry updated successfully', [
                'entry_id' => $logisticsLogEntry->id,
                'new_status' => $logisticsLogEntry->fresh()->status
            ]);

            // Load relationships for response
            $logisticsLogEntry->load(['projectEnquiry', 'creator']);

            return response()->json([
                'message' => 'Logistics log entry updated successfully',
                'data' => [
                    'id' => $logisticsLogEntry->id,
                    'project_id' => $logisticsLogEntry->project_enquiry_id,
                    'site' => $logisticsLogEntry->site,
                    'loading_time' => $logisticsLogEntry->loading_time?->toISOString(),
                    'departure' => $logisticsLogEntry->departure?->toISOString(),
                    'vehicle_allocated' => $logisticsLogEntry->vehicle_allocated,
                    'project_officer_incharge' => $logisticsLogEntry->project_officer_incharge,
                    'remarks' => $logisticsLogEntry->remarks,
                    'status' => $logisticsLogEntry->status,
                    'created_at' => $logisticsLogEntry->created_at?->toISOString(),
                    'project_name' => $logisticsLogEntry->projectEnquiry?->title ?? 'Unknown Project',
                    'creator_name' => $logisticsLogEntry->creator?->name ?? 'Unknown User',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update logistics log entry',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a logistics log entry.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $logisticsLogEntry = LogisticsLogEntry::findOrFail($id);
            $logisticsLogEntry->delete();

            return response()->json([
                'message' => 'Logistics log entry deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete logistics log entry',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
