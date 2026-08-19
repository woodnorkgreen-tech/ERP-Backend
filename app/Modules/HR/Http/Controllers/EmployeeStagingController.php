<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeStagingRecord;
use App\Modules\HR\Services\EmployeeStagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeStagingController
{
    public function __construct(
        private EmployeeStagingService $stagingService
    ) {}

    /**
     * Upload an Excel/CSV dump file into the staging repository.
     */
    public function upload(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $result = $this->stagingService->importDump($request->file('file'), auth()->id());

        return response()->json($result, 201);
    }

    /**
     * Get staged record and field comparisons for an employee by email or employee_id.
     */
    public function matchByEmail(Request $request, string $email): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $decodedEmail = urldecode($email);
        $employeeId = $request->query('employee_id');

        // Find target employee record
        $employee = Employee::where('email', mb_strtolower(trim($decodedEmail)))
            ->when($employeeId, fn ($q) => $q->orWhere('employee_id', trim($employeeId)))
            ->first();

        $empEmail = $employee?->email ?? $decodedEmail;
        $empCode = $employee?->employee_id ?? $employeeId;
        $empDbId = $employee?->id;

        // Find active staging record using email, employee_id, or matched_employee_id
        $stagingRecord = EmployeeStagingRecord::active()
            ->where(function ($q) use ($empEmail, $empCode, $empDbId) {
                if ($empEmail) {
                    $q->where('email', mb_strtolower(trim($empEmail)));
                }
                if ($empCode) {
                    $q->orWhere('employee_id', trim($empCode));
                }
                if ($empDbId) {
                    $q->orWhere('matched_employee_id', $empDbId);
                }
            })
            ->latest('id')
            ->first();

        if (! $stagingRecord) {
            return response()->json([
                'has_staged_data' => false,
                'message' => 'No staged data found for this employee.',
            ]);
        }

        $diffs = $this->computeDiffs($employee, $stagingRecord->staged_data);

        return response()->json([
            'has_staged_data' => true,
            'staging_id' => $stagingRecord->id,
            'batch_name' => $stagingRecord->batch_name,
            'uploaded_at' => $stagingRecord->created_at?->toIso8601String(),
            'staged_data' => $stagingRecord->staged_data,
            'diffs' => $diffs,
        ]);
    }

    /**
     * List all active staged records for dropdown selection in Create/Edit modals.
     */
    public function listRecords(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $records = EmployeeStagingRecord::active()
            ->latest('id')
            ->get(['id', 'batch_id', 'batch_name', 'email', 'employee_id', 'id_number', 'matched_employee_id', 'staged_data', 'created_at']);

        return response()->json([
            'records' => $records,
        ]);
    }

    /**
     * List all dump batches in the staging repository.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $batches = EmployeeStagingRecord::query()
            ->selectRaw('batch_id, batch_name, uploaded_by, created_at, COUNT(*) as total_rows, SUM(CASE WHEN matched_employee_id IS NOT NULL THEN 1 ELSE 0 END) as matched_count')
            ->groupBy('batch_id', 'batch_name', 'uploaded_by', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'batches' => $batches,
        ]);
    }

    /**
     * Delete/purge a dump batch.
     */
    public function destroyBatch(string $batchId): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $count = EmployeeStagingRecord::where('batch_id', $batchId)->delete();

        return response()->json([
            'message' => "Staging dump batch '{$batchId}' deleted successfully ({$count} records removed).",
        ]);
    }

    /**
     * Mark a staged record as applied.
     */
    public function markApplied(int $id): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $record = EmployeeStagingRecord::findOrFail($id);
        $record->update(['status' => 'fully_applied']);

        return response()->json([
            'message' => 'Staged record marked as applied.',
        ]);
    }

    /**
     * Compute field-level comparison between target employee and staged data.
     */
    private function computeDiffs(?Employee $employee, array $stagedData): array
    {
        if (! $employee) {
            return [];
        }

        $fieldMap = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'id_number' => 'id_number',
            'kra_pin' => 'kra_pin',
            'nssf_id' => 'nssf_id',
            'nhif_id' => 'nhif_id',
            'phone' => 'phone',
            'position' => 'position',
            'hire_date' => 'hire_date',
            'gender' => 'gender',
            'date_of_birth' => 'date_of_birth',
            'address' => 'address',
            'salary' => 'salary',
            'bank_name' => 'bank_name',
            'bank_branch' => 'bank_branch',
            'bank_code' => 'bank_code',
            'account_number' => 'account_number',
        ];

        $diffs = [];

        foreach ($fieldMap as $stagedKey => $empAttr) {
            if (! isset($stagedData[$stagedKey]) || $stagedData[$stagedKey] === '') {
                continue;
            }

            $stagedVal = (string) $stagedData[$stagedKey];
            $currentVal = $employee->getAttribute($empAttr);

            if ($currentVal instanceof \Carbon\CarbonInterface) {
                $currentVal = $currentVal->format('Y-m-d');
            } else {
                $currentVal = $currentVal !== null ? (string) $currentVal : null;
            }

            $isMatch = ($currentVal !== null && mb_strtolower(trim($currentVal)) === mb_strtolower(trim($stagedVal)));

            $diffs[$empAttr] = [
                'current' => $currentVal,
                'staged' => $stagedVal,
                'is_empty_current' => ($currentVal === null || trim($currentVal) === ''),
                'is_match' => $isMatch,
            ];
        }

        // Emergency contact fields handling
        $emergencyObj = $employee->emergency_contact ?? [];
        if (isset($stagedData['emergency_contact_name'])) {
            $currName = $emergencyObj['name'] ?? null;
            $diffs['emergency_contact_name'] = [
                'current' => $currName,
                'staged' => $stagedData['emergency_contact_name'],
                'is_empty_current' => empty($currName),
                'is_match' => (mb_strtolower(trim((string) $currName)) === mb_strtolower(trim((string) $stagedData['emergency_contact_name']))),
            ];
        }

        if (isset($stagedData['emergency_contact_phone'])) {
            $currPhone = $emergencyObj['phone'] ?? null;
            $diffs['emergency_contact_phone'] = [
                'current' => $currPhone,
                'staged' => $stagedData['emergency_contact_phone'],
                'is_empty_current' => empty($currPhone),
                'is_match' => (mb_strtolower(trim((string) $currPhone)) === mb_strtolower(trim((string) $stagedData['emergency_contact_phone']))),
            ];
        }

        return $diffs;
    }
}
