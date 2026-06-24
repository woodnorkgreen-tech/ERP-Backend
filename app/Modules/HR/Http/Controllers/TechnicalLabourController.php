<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TechnicalLabour;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Promote a technical-labour specialist into a full Employee record.
     *
     * Carries over the identity fields already held on the specialist
     * (name, contacts, ID, specialization → position, rating) and merges in the
     * HR-supplied fields that have no equivalent on the registry (department,
     * hire date, salary, employment type). Request input wins over carried-over
     * defaults so HR can correct anything in the promotion form.
     *
     * The source TechnicalLabour record is intentionally left untouched, and no
     * overtime/ledger history is migrated — first rollout creates the employee
     * with a clean slate.
     */
    public function promote(Request $request, TechnicalLabour $technicalLabour): JsonResponse
    {
        // Idempotency: a specialist may only be promoted once. Without this guard a repeat
        // promotion mints a duplicate employee (email — the only DB dedupe — is nullable).
        if ($technicalLabour->isPromoted()) {
            return response()->json([
                'message' => 'This specialist has already been promoted to an employee.',
                'data'    => $technicalLabour->employee()->with(['department', 'manager'])->first(),
            ], 409);
        }

        // Default first/last name from the registry's single full_name field.
        $nameParts = preg_split('/\s+/', trim((string) $technicalLabour->full_name), 2);

        $payload = array_merge(
            [
                'first_name'         => $nameParts[0] ?? '',
                'last_name'          => $nameParts[1] ?? '',
                'email'              => $technicalLabour->email,
                'phone'              => $technicalLabour->phone,
                'id_number'          => $technicalLabour->id_number,
                'position'           => $technicalLabour->specialization,
                'performance_rating' => $technicalLabour->rating,
                'status'             => 'active',
            ],
            // Drop blank request values so they don't clobber the carried-over defaults.
            array_filter($request->all(), fn ($v) => $v !== null && $v !== '')
        );

        $validator = Validator::make($payload, [
            'first_name'         => 'required|string|max:255',
            'last_name'          => 'required|string|max:255',
            'email'              => 'nullable|email|unique:employees,email',
            'phone'              => 'nullable|string|max:20',
            'id_number'          => 'nullable|string|max:20|unique:employees,id_number',
            'department_id'      => 'required|exists:departments,id',
            'position'           => 'required|string|max:255',
            'hire_date'          => 'required|date',
            'salary'             => 'nullable|numeric|min:0',
            'employment_type'    => ['nullable', Rule::in(['full-time', 'part-time', 'contract', 'intern'])],
            'status'             => ['required', Rule::in(['active', 'inactive', 'terminated', 'on-leave'])],
            'manager_id'         => 'nullable|exists:employees,id',
            'kra_pin'            => 'nullable|string|max:20',
            'nssf_id'            => 'nullable|string|max:20',
            'nhif_id'            => 'nullable|string|max:20',
            'bank_name'          => 'nullable|string|max:255',
            'bank_branch'        => 'nullable|string|max:255',
            'bank_code'          => 'nullable|string|max:20',
            'account_number'     => 'nullable|string|max:50',
            'payment_method'     => ['nullable', Rule::in(['bank', 'mpesa', 'mobile_money', 'cash', 'cheque'])],
            'date_of_birth'      => 'nullable|date',
            'address'            => 'nullable|string',
            'performance_rating' => 'nullable|numeric|min:0|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $employee = DB::transaction(function () use ($validator, $technicalLabour) {
            $data = $validator->validated();

            // employees.employee_id is NOT NULL + UNIQUE with no default, so it must
            // be present at insert. Use a transient unique placeholder, then derive
            // the final staff number from the real auto-increment id (race-safe).
            $data['employee_id'] = 'PENDING-' . uniqid('', true);
            $employee = Employee::create($data);

            $employee->employee_id = 'EMP' . str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT);
            $employee->save();

            // Link the registry record to the new staff record and retire it from the
            // bookable pool, so the same person can't be promoted (or scheduled) twice.
            $technicalLabour->update([
                'employee_id' => $employee->id,
                'promoted_at' => now(),
                'status'      => 'inactive',
            ]);

            return $employee;
        });

        return response()->json([
            'message' => 'Specialist promoted to employee successfully',
            'data' => $employee->load(['department', 'manager'])
        ], 201);
    }

    /**
     * Download CSV Template for Import
     */
    public function downloadTemplate()
    {
        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=technical_labour_template.csv",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $columns = ['Full Name', 'Phone', 'Email', 'ID Number', 'Specialization', 'Day Rate', 'Status', 'Rating', 'Notes'];

        $callback = function() use ($columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            // Example Row
            fputcsv($file, ['John Doe', '0700123456', 'john@example.com', '12345678', 'Electrician', '2500', 'active', '5', 'Example Notes']);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Import Technical Labour from CSV
     */
    public function import(Request $request): JsonResponse
    {
        // Relaxed validation
        $validator = Validator::make($request->all(), [
            'file' => 'required|file'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Upload failed', 'errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $path = $file->getRealPath();

        // Detect line endings for cross-platform compatibility
        if (!ini_get("auto_detect_line_endings")) {
            ini_set("auto_detect_line_endings", '1');
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return response()->json(['message' => 'Could not open file'], 500);
        }

        // Skip Header Row
        fgetcsv($handle);

        $count = 0;
        $errors = 0;

        try {
            DB::beginTransaction();
            
            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty or invalid rows (less than required columns)
                // Template has 9 columns. We need at least Name (0).
                if (empty($row) || count($row) < 1 || empty($row[0])) continue;

                // 0:Name, 1:Phone, 2:Email, 3:ID, 4:Spec, 5:Rate, 6:Status, 7:Rating, 8:Notes
                
                try {
                    $status = isset($row[6]) && in_array(strtolower(trim($row[6])), ['active', 'inactive', 'blacklisted']) 
                        ? strtolower(trim($row[6])) 
                        : 'active';

                    TechnicalLabour::create([
                        'full_name'      => trim($row[0]),
                        'phone'          => isset($row[1]) ? trim($row[1]) : null,
                        'email'          => isset($row[2]) ? trim($row[2]) : null,
                        'id_number'      => isset($row[3]) ? trim($row[3]) : null,
                        'specialization' => isset($row[4]) ? trim($row[4]) : null,
                        'day_rate'       => isset($row[5]) ? (float) preg_replace('/[^0-9.]/', '', $row[5]) : 0,
                        'status'         => $status,
                        'rating'         => isset($row[7]) ? (float) $row[7] : 5,
                        'notes'          => isset($row[8]) ? trim($row[8]) : null,
                    ]);
                    $count++;
                } catch (\Exception $e) {
                    \Log::error('Import Row Failed: ' . json_encode($row) . ' Error: ' . $e->getMessage());
                    $errors++;
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            fclose($handle);
            \Log::error('Bulk Import Transaction Failed: ' . $e->getMessage());
            return response()->json(['message' => 'Import failed during processing', 'error' => $e->getMessage()], 500);
        }

        fclose($handle);

        return response()->json([
            'message' => "Successfully imported {$count} records." . ($errors > 0 ? " Skipped {$errors} invalid rows." : ""),
            'count' => $count
        ]);
    }
}
