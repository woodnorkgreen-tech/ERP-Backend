<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeCertification;
use App\Modules\HR\Models\EmployeeSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeSkillController
{
    // ── Skills ──────────────────────────────────────────────────────────────

    public function indexSkills(Employee $employee): JsonResponse
    {
        return response()->json($employee->skills()->orderBy('skill_name')->get());
    }

    public function storeSkill(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'skill_name'       => 'required|string|max:100',
            'proficiency'      => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced', 'expert'])],
            'years_experience' => 'nullable|integer|min:0|max:50',
            'notes'            => 'nullable|string|max:500',
        ]);

        $skill = $employee->skills()->create($data);

        return response()->json($skill, 201);
    }

    public function updateSkill(Request $request, Employee $employee, EmployeeSkill $skill): JsonResponse
    {
        abort_if($skill->employee_id !== $employee->id, 404);

        $data = $request->validate([
            'skill_name'       => 'sometimes|string|max:100',
            'proficiency'      => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced', 'expert'])],
            'years_experience' => 'nullable|integer|min:0|max:50',
            'notes'            => 'nullable|string|max:500',
        ]);

        $skill->update($data);

        return response()->json($skill);
    }

    public function destroySkill(Employee $employee, EmployeeSkill $skill): JsonResponse
    {
        abort_if($skill->employee_id !== $employee->id, 404);
        $skill->delete();

        return response()->json(['message' => 'Skill removed.']);
    }

    // ── Certifications ───────────────────────────────────────────────────────

    public function indexCertifications(Employee $employee): JsonResponse
    {
        return response()->json($employee->certifications()->get()->map(fn ($c) => array_merge($c->toArray(), [
            'is_expired'       => $c->is_expired,
            'is_expiring_soon' => $c->is_expiring_soon,
        ])));
    }

    public function storeCertification(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:200',
            'issued_by'   => 'nullable|string|max:200',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'cert_number' => 'nullable|string|max:100',
            'notes'       => 'nullable|string|max:500',
        ]);

        $cert = $employee->certifications()->create($data);

        return response()->json(array_merge($cert->toArray(), [
            'is_expired'       => $cert->is_expired,
            'is_expiring_soon' => $cert->is_expiring_soon,
        ]), 201);
    }

    public function updateCertification(Request $request, Employee $employee, EmployeeCertification $certification): JsonResponse
    {
        abort_if($certification->employee_id !== $employee->id, 404);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:200',
            'issued_by'   => 'nullable|string|max:200',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'cert_number' => 'nullable|string|max:100',
            'notes'       => 'nullable|string|max:500',
        ]);

        $certification->update($data);
        $certification->refresh();

        return response()->json(array_merge($certification->toArray(), [
            'is_expired'       => $certification->is_expired,
            'is_expiring_soon' => $certification->is_expiring_soon,
        ]));
    }

    public function destroyCertification(Employee $employee, EmployeeCertification $certification): JsonResponse
    {
        abort_if($certification->employee_id !== $employee->id, 404);
        $certification->delete();

        return response()->json(['message' => 'Certification removed.']);
    }
}
