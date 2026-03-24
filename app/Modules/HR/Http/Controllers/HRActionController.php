<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HRActionController
{
    /**
     * Display a listing of HR actions for a specific employee.
     */
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => $employee->actions()->with('recorder')->get()
        ]);
    }

    /**
     * Store a newly created HR action.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'action_type' => ['required', Rule::in(['promotion', 'transfer', 'warning', 'salary_update', 'termination'])],
            'effective_date' => 'required|date',
            'reason' => 'nullable|string',
            'new_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validatedData = $validator->validated();
        $employee = Employee::findOrFail($validatedData['employee_id']);
        
        // Prepare previous data based on action type
        $previousData = [];
        $newData = $validatedData['new_data'] ?? [];
        
        switch ($validatedData['action_type']) {
            case 'promotion':
            case 'transfer':
                if (isset($newData['position'])) $previousData['position'] = $employee->position;
                if (isset($newData['department_id'])) $previousData['department_id'] = $employee->department_id;
                if (isset($newData['salary'])) $previousData['salary'] = $employee->salary;
                break;
            case 'salary_update':
                $previousData['salary'] = $employee->salary;
                break;
            case 'termination':
                $previousData['status'] = $employee->status;
                break;
        }

        return DB::transaction(function () use ($employee, $validatedData, $previousData, $newData) {
            // 1. Create the HR Action record
            $action = HRAction::create([
                'employee_id' => $employee->id,
                'action_type' => $validatedData['action_type'],
                'previous_data' => $previousData,
                'new_data' => $newData,
                'effective_date' => $validatedData['effective_date'],
                'reason' => $validatedData['reason'],
                'recorded_by' => auth()->id() ?? 1, // Fallback for testing if no auth
            ]);

            // 2. Update the Employee record (except for warnings)
            if ($validatedData['action_type'] !== 'warning') {
                $employee->update($newData);
            }

            return response()->json([
                'message' => 'HR Action recorded successfully',
                'data' => $action->load('employee')
            ], 201);
        });
    }
}
