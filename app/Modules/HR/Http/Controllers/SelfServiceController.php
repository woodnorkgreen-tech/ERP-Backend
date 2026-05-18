<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\ProfileUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SelfServiceController extends Controller
{
    /**
     * Submit a profile update request.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $employee = auth()->user()->employee; 
        
        if (!$employee) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'new_data' => 'required|array',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only allow specific safe fields
        $allowedFields = [
            'first_name', 'last_name', 'date_of_birth',
            'id_number', 'kra_pin', 'nssf_id', 'nhif_id',
            'phone', 'email', 'address',
            'bank_name', 'bank_branch', 'bank_code', 'account_number', 'payment_method',
            'emergency_name', 'emergency_relationship', 'emergency_phone'
        ];

        $inputData = $request->input('new_data', []);
        $newData = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $inputData)) {
                $newData[$field] = $inputData[$field];
            }
        }

        if (empty($newData)) {
            return response()->json(['message' => 'No valid fields provided for update.'], 422);
        }

        // Transform flat emergency fields to the expected JSON array structure
        if (array_key_exists('emergency_name', $newData) || array_key_exists('emergency_relationship', $newData) || array_key_exists('emergency_phone', $newData)) {
            $newData['emergency_contact'] = [
                'name' => $newData['emergency_name'] ?? null,
                'relationship' => $newData['emergency_relationship'] ?? null,
                'phone' => $newData['emergency_phone'] ?? null,
            ];
            unset($newData['emergency_name'], $newData['emergency_relationship'], $newData['emergency_phone']);
        }

        $requestRecord = ProfileUpdateRequest::create([
            'employee_id' => $employee->id,
            'requested_data' => $newData,
            'reason' => $request->reason,
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Profile update request submitted for HR approval',
            'data' => $requestRecord
        ], 201);
    }
}
