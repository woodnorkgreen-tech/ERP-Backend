<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\TechnicalLabour;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TechnicalLabourController
{
    /**
     * Display a listing of technical labour.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TechnicalLabour::query();

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('specialization', 'like', "%{$search}%");
            });
        }

        $labours = $query->orderBy('full_name')->get();
        return response()->json($labours);
    }

    /**
     * Store a newly created technical labour.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'id_number' => 'nullable|string|max:50',
            'specialization' => 'nullable|string|max:255',
            'day_rate' => 'nullable|numeric|min:0',
            'status' => ['required', Rule::in(['active', 'inactive', 'blacklisted'])],
            'rating' => 'nullable|numeric|min:0|max:5',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $labour = TechnicalLabour::create($validator->validated());

        return response()->json([
            'message' => 'Technical labour created successfully',
            'data' => $labour
        ], 201);
    }

    /**
     * Display the specified technical labour.
     */
    public function show(TechnicalLabour $technicalLabour): JsonResponse
    {
        return response()->json($technicalLabour);
    }

    /**
     * Update the specified technical labour.
     */
    public function update(Request $request, TechnicalLabour $technicalLabour): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'id_number' => 'nullable|string|max:50',
            'specialization' => 'nullable|string|max:255',
            'day_rate' => 'nullable|numeric|min:0',
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'blacklisted'])],
            'rating' => 'nullable|numeric|min:0|max:5',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $technicalLabour->update($validator->validated());

        return response()->json([
            'message' => 'Technical labour updated successfully',
            'data' => $technicalLabour
        ]);
    }

    /**
     * Remove the specified technical labour.
     */
    public function destroy(TechnicalLabour $technicalLabour): JsonResponse
    {
        $technicalLabour->delete();

        return response()->json([
            'message' => 'Technical labour deleted successfully'
        ]);
    }
}
