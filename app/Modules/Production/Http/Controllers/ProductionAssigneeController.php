<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TechnicalLabour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionAssigneeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->get('search', '');
        $limit = (int) $request->get('limit', 15);

        $employees = Employee::query()
            ->where('status', 'active')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->limit($limit)
            ->get()
            ->map(function ($employee) {
                return [
                    'id' => $employee->id,
                    'type' => 'employee',
                    'name' => $employee->name,
                    'label' => $employee->name . ' (Employee)'
                ];
            });

        $labours = TechnicalLabour::query()
            ->where('status', 'active')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('specialization', 'like', "%{$search}%");
                });
            })
            ->limit($limit)
            ->get()
            ->map(function ($labour) {
                return [
                    'id' => $labour->id,
                    'type' => 'technical_labour',
                    'name' => $labour->full_name,
                    'label' => $labour->full_name . ' (Technician)'
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $employees->merge($labours)->values()
        ]);
    }
}
