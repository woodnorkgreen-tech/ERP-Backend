<?php

namespace App\Modules\HR\Imports;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\EmployeeTemplateSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Reads a reuploaded employee template, validates it, and works out exactly what would
 * change — WITHOUT touching the database. The controller calls getAnalysis() to show the
 * user a diff (preview), then apply() to actually write the non-error rows (commit).
 *
 * Row → record matching (the "anchor"), in priority order:
 *   1. System ID (db_id)      — stable, system-managed
 *   2. Employee No            — business key
 *   3. Email                  — unique identifier
 * No match on any anchor => the row creates a new employee.
 *
 * A BLANK cell means "leave this field unchanged" (you edit only the cells you want to
 * change). This is deliberately not "clear to null": a half-filled upload must never
 * silently wipe data, and blank cells can't be distinguished from values the spreadsheet
 * engine omits. Salary/bank columns are ignored entirely for users without the
 * employee.view_salary permission.
 */
class EmployeesTemplateImport implements ToCollection, WithHeadingRow, WithMultipleSheets
{
    /**
     * Only ever parse the first sheet ("Employees"). The export ships a second, hidden
     * "Lists" sheet holding the dropdown option lists (statuses, departments, manager numbers…).
     * Without restricting to sheet 0, Excel::import concatenates EVERY sheet, so each Lists row
     * gets read as a phantom employee — status/department cells set but no name — producing a
     * wave of bogus "first name is required" rows. Pinning to index 0 keeps the options out.
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    /** @var array<int,array> Per-row analysis: action, payload, changes, errors. */
    private array $rows = [];

    /** Lowercased department name => id. */
    private array $departmentsByName = [];

    /** Employee No => db id (for manager resolution). */
    private array $employeesByNo = [];

    /** db id => true for every employee this user is allowed to touch. */
    private array $accessibleIds = [];

    /** In-file anchor tracking, so two rows can't collide at commit. value = first row number seen. */
    private array $seenTargetIds = [];
    private array $seenEmails = [];
    private array $seenEmployeeNos = [];

    /** The authenticated user this import runs as (drives access scoping). */
    private $user;

    /**
     * @param bool $canViewSalary  Honour/expose salary + bank columns.
     * @param bool $canCreate      Whether rows with no existing match may create new employees.
     * @param mixed $user          The acting user (defaults to the authenticated user).
     */
    public function __construct(
        private bool $canViewSalary,
        private bool $canCreate = true,
        $user = null,
    ) {
        $this->user = $user ?? auth()->user();

        Department::query()->select(['id', 'name'])->get()
            ->each(fn ($d) => $this->departmentsByName[mb_strtolower(trim($d->name))] = $d->id);

        Employee::query()->whereNotNull('employee_id')->pluck('id', 'employee_id')
            ->each(fn ($id, $no) => $this->employeesByNo[(string) $no] = $id);

        // The set of employees this user may touch. The export only ever hands out accessible
        // rows, so a reuploaded row anchoring outside this set means someone hand-typed an
        // id/email they shouldn't reach — that row is rejected (see analyzeRow).
        $this->accessibleIds = Employee::query()->accessibleByUser($this->user)
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    public function headingRow(): int
    {
        return 1;
    }

    public function collection(Collection $excelRows): void
    {
        $map = EmployeeTemplateSchema::headerKeyToField();
        $rowNumber = 1; // header is row 1; data starts at 2

        $this->assertLooksLikeTemplate($excelRows, $map);

        foreach ($excelRows as $excelRow) {
            $rowNumber++;

            // Translate slugged headers back to our internal field keys.
            $input = [];
            foreach ($map as $headerKey => $field) {
                $input[$field] = $this->str($excelRow[$headerKey] ?? null);
            }

            if ($this->isEmptyRow($input)) {
                continue;
            }

            $this->rows[] = $this->analyzeRow($rowNumber, $input);
        }
    }

    private function analyzeRow(int $rowNumber, array $input): array
    {
        $errors = [];

        // --- 1. Resolve the existing record via the anchor priority chain ---
        $existing = $this->matchExisting($input, $errors);

        // --- 1a. Access scope: you may only change records you are allowed to access ---
        if ($existing && ! isset($this->accessibleIds[$existing->id])) {
            $errors[] = "You do not have permission to modify '" .
                trim(($existing->first_name ?? '') . ' ' . ($existing->last_name ?? '')) .
                "' — it is outside your access scope.";
        }

        // --- 1b. In-file duplicate anchors: two rows pointing at the same person, or claiming
        //         the same new Employee No/Email, would collide and roll back the whole batch ---
        $this->guardDuplicates($rowNumber, $input, $existing, $errors);

        // --- 2. Build the normalized payload (FK resolution, casts, transforms) ---
        $payload = $this->buildPayload($input, $errors);

        $isCreate = $existing === null && empty($errors);

        // --- 2a. Creating a new employee requires the create permission, not just update ---
        if ($isCreate && ! $this->canCreate) {
            $errors[] = 'You do not have permission to add new employees. This row matches no ' .
                'existing System ID, Employee No, or Email, so it would create a new record.';
            $isCreate = false;
        }

        // --- 3. Validate (rules mirror EmployeeController, create vs update variant) ---
        if (empty($errors)) {
            $validator = Validator::make($payload, $this->rules($existing?->id, $isCreate));
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = $message;
                }
            }
        }

        // --- 4. Diff against the existing record (only changed fields). Skipped for error rows
        //         so an access-denied row can't leak the real record's values through the preview.
        $changes = ($existing && empty($errors)) ? $this->diff($existing, $payload) : [];

        $action = ! empty($errors)
            ? 'error'
            : ($existing ? (empty($changes) ? 'unchanged' : 'update') : 'create');

        return [
            'row'        => $rowNumber,
            'action'     => $action,
            'target_id'  => $existing?->id,
            'identity'   => trim(($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? ''))
                                ?: ($input['email'] ?? $input['employee_id'] ?? "row {$rowNumber}"),
            'payload'    => $payload,
            'changes'    => $changes,
            'errors'     => $errors,
        ];
    }

    /**
     * Find the existing employee for this row using db_id → employee_id → email.
     * A db_id that resolves to nothing is a hard error (guards against typos creating phantoms).
     */
    private function matchExisting(array $input, array &$errors): ?Employee
    {
        if (! empty($input['db_id'])) {
            $found = Employee::find((int) $input['db_id']);
            if (! $found) {
                $errors[] = "System ID '{$input['db_id']}' does not match any employee. Clear it to create a new record.";
            }
            return $found;
        }

        if (! empty($input['employee_id'])) {
            $found = Employee::where('employee_id', $input['employee_id'])->first();
            if ($found) {
                return $found;
            }
        }

        if (! empty($input['email'])) {
            $found = Employee::where('email', $input['email'])->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Reject duplicate anchors *within the same upload*. Validation's unique rules only see the
     * database, so two new rows sharing an Email/Employee No both pass preview and then explode
     * on the second INSERT at commit — aborting the whole transaction. We catch every copy here
     * (each duplicate row is flagged, pointing back at the first occurrence) so the batch never
     * reaches a state the database would reject.
     */
    private function guardDuplicates(int $rowNumber, array $input, ?Employee $existing, array &$errors): void
    {
        if ($existing) {
            if (isset($this->seenTargetIds[$existing->id])) {
                $errors[] = "This employee is already changed in row {$this->seenTargetIds[$existing->id]}. Keep one row per employee.";
            } else {
                $this->seenTargetIds[$existing->id] = $rowNumber;
            }
        }

        if ($this->filled($input['email'])) {
            $key = mb_strtolower($input['email']);
            if (isset($this->seenEmails[$key])) {
                $errors[] = "Email '{$input['email']}' is also used in row {$this->seenEmails[$key]}. Each email must be unique.";
            } else {
                $this->seenEmails[$key] = $rowNumber;
            }
        }

        if ($this->filled($input['employee_id'])) {
            $key = (string) $input['employee_id'];
            if (isset($this->seenEmployeeNos[$key])) {
                $errors[] = "Employee No '{$input['employee_id']}' is also used in row {$this->seenEmployeeNos[$key]}. Each Employee No must be unique.";
            } else {
                $this->seenEmployeeNos[$key] = $rowNumber;
            }
        }
    }

    /**
     * Fail fast if the upload isn't actually our template. Without this, an unrelated workbook
     * slugs to headers we don't recognise, every row reads as blank, and the import silently
     * reports "0 changes" as if it succeeded.
     */
    private function assertLooksLikeTemplate(Collection $excelRows, array $map): void
    {
        $first = $excelRows->first();
        if ($first === null) {
            return; // genuinely empty sheet — apply() correctly reports zero rows
        }

        $present = array_keys($first->toArray());
        if (empty(array_intersect(array_keys($map), $present))) {
            throw ValidationException::withMessages([
                'file' => ['This file does not match the employee template (no recognised columns). Download a fresh template and edit that copy.'],
            ]);
        }
    }

    /**
     * Translate the raw cell values into a model-ready attribute payload.
     * Only non-blank cells are included — a blank cell leaves that field untouched.
     */
    private function buildPayload(array $input, array &$errors): array
    {
        $payload = [];

        // Plain passthrough text fields (set only when provided).
        foreach (['employee_id', 'email', 'first_name', 'last_name', 'phone', 'position',
                  'address', 'id_number', 'kra_pin', 'nssf_id', 'nhif_id', 'hikvision_id'] as $field) {
            if ($this->filled($input[$field])) {
                $payload[$field] = $input[$field];
            }
        }

        // Lowercased enum fields.
        foreach (['status', 'employment_type'] as $field) {
            if ($this->filled($input[$field])) {
                $payload[$field] = mb_strtolower($input[$field]);
            }
        }

        // Dates.
        $dates = [
            'hire_date' => 'Hire Date', 'probation_end_date' => 'Probation End Date',
            'contract_end_date' => 'Contract End Date', 'date_of_birth' => 'Date of Birth',
            'last_review_date' => 'Last Review Date',
        ];
        foreach ($dates as $field => $label) {
            if ($this->filled($input[$field])) {
                $payload[$field] = $this->date($input[$field], $label, $errors);
            }
        }

        if ($this->filled($input['is_on_probation'])) {
            $payload['is_on_probation'] = $this->bool($input['is_on_probation']);
        }

        if ($this->filled($input['performance_rating'])) {
            $payload['performance_rating'] = $input['performance_rating'];
        }

        if ($this->filled($input['statutory_exemptions'])) {
            $payload['statutory_exemptions'] = $this->exemptions($input['statutory_exemptions'], $errors);
        }

        // Emergency contact: any of the three cells filled replaces the whole object.
        $emergency = $this->emergencyContact($input);
        if ($emergency !== null) {
            $payload['emergency_contact'] = $emergency;
        }

        // Department name → id
        if ($this->filled($input['department'])) {
            $deptId = $this->departmentsByName[mb_strtolower(trim($input['department']))] ?? null;
            if ($deptId === null) {
                $errors[] = "Department '{$input['department']}' not found.";
            }
            $payload['department_id'] = $deptId;
        }

        // Manager Employee No → id
        if ($this->filled($input['manager_employee_no'])) {
            $managerId = $this->employeesByNo[(string) $input['manager_employee_no']] ?? null;
            if ($managerId === null) {
                $errors[] = "Manager Employee No '{$input['manager_employee_no']}' not found.";
            }
            $payload['manager_id'] = $managerId;
        }

        // Salary/bank: only honoured when the user may view salary; otherwise dropped entirely.
        if ($this->canViewSalary) {
            if ($this->filled($input['salary'])) {
                $payload['salary'] = $input['salary'];
            }
            if ($this->filled($input['payment_method'])) {
                $payload['payment_method'] = mb_strtolower($input['payment_method']);
            }
            foreach (['bank_name', 'bank_branch', 'bank_code', 'account_number'] as $field) {
                if ($this->filled($input[$field])) {
                    $payload[$field] = $input[$field];
                }
            }
        }

        return $payload;
    }

    private function filled($value): bool
    {
        return $value !== '' && $value !== null;
    }

    /**
     * Validation rules mirroring EmployeeController::store/update.
     * Unique rules ignore the matched record so a round-tripped row doesn't collide with itself.
     */
    private function rules(?int $ignoreId, bool $isCreate): array
    {
        $req = $isCreate ? 'required' : 'nullable';

        return [
            'employee_id'     => [$isCreate ? 'nullable' : 'sometimes', Rule::unique('employees', 'employee_id')->ignore($ignoreId)],
            'email'           => ['nullable', 'email', Rule::unique('employees', 'email')->ignore($ignoreId)],
            'hikvision_id'    => ['nullable', 'string', 'max:50', Rule::unique('employees', 'hikvision_id')->ignore($ignoreId)],
            'first_name'      => [$req, 'string', 'max:255'],
            'last_name'       => [$req, 'string', 'max:255'],
            'department_id'   => [$req, 'exists:departments,id'],
            'position'        => [$req, 'string', 'max:255'],
            'hire_date'       => [$req, 'date'],
            'status'          => [$req, Rule::in(EmployeeTemplateSchema::STATUS_OPTIONS)],
            'employment_type' => ['nullable', Rule::in(EmployeeTemplateSchema::EMPLOYMENT_TYPE_OPTIONS)],
            'payment_method'  => ['nullable', Rule::in(EmployeeTemplateSchema::PAYMENT_METHOD_OPTIONS)],
            'manager_id'      => ['nullable', 'exists:employees,id'],
            'salary'          => ['nullable', 'numeric', 'min:0'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'id_number'       => ['nullable', 'string', 'max:20'],
            'kra_pin'         => ['nullable', 'string', 'max:20'],
            'nssf_id'         => ['nullable', 'string', 'max:20'],
            'nhif_id'         => ['nullable', 'string', 'max:20'],
            'performance_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'probation_end_date' => ['nullable', 'date'],
            'contract_end_date'  => ['nullable', 'date'],
            'date_of_birth'      => ['nullable', 'date'],
            'last_review_date'   => ['nullable', 'date'],
            'statutory_exemptions'   => ['nullable', 'array'],
            'statutory_exemptions.*' => ['string', Rule::in(Employee::STATUTORY_EXEMPTIONS)],
        ];
    }

    /**
     * Writes the analyzed rows (create/update) for everything without errors.
     * Salary history is handled automatically by EmployeeObserver, so we just save.
     *
     * @return array{created:int,updated:int,skipped:int,outcomes:array}
     */
    public function apply(): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $outcomes = [];

        DB::transaction(function () use (&$created, &$updated, &$skipped, &$outcomes) {
            foreach ($this->rows as $row) {
                if ($row['action'] === 'error') {
                    $skipped++;
                    $outcomes[] = ['row' => $row['row'], 'status' => 'skipped', 'errors' => $row['errors']];
                    continue;
                }

                if ($row['action'] === 'unchanged') {
                    $outcomes[] = ['row' => $row['row'], 'status' => 'unchanged'];
                    continue;
                }

                $payload = $this->writablePayload($row['payload']);

                if ($row['action'] === 'create') {
                    $employee = $this->createEmployee($payload);
                    $created++;
                    $outcomes[] = ['row' => $row['row'], 'status' => 'created', 'id' => $employee->id, 'employee_id' => $employee->employee_id];
                } else { // update
                    $employee = Employee::find($row['target_id']);
                    if (! $employee) {
                        $skipped++;
                        $outcomes[] = ['row' => $row['row'], 'status' => 'skipped', 'errors' => ['Record disappeared before commit.']];
                        continue;
                    }
                    $employee->update($payload);
                    $updated++;
                    $outcomes[] = ['row' => $row['row'], 'status' => 'updated', 'id' => $employee->id];
                }
            }
        });

        return compact('created', 'updated', 'skipped', 'outcomes');
    }

    /**
     * Create with the same employee_id generation strategy as EmployeeController::store:
     * employees.employee_id is NOT NULL + UNIQUE, so derive EMP#### from the real id when absent.
     */
    private function createEmployee(array $payload): Employee
    {
        $customId = $payload['employee_id'] ?? null;
        $payload['employee_id'] = $customId ?: 'PENDING-' . uniqid('', true);

        $employee = Employee::create($payload);

        if (! $customId) {
            $employee->employee_id = 'EMP' . str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT);
            $employee->save();
        }

        return $employee;
    }

    /** Strip read-only/anchor keys that must never be written. */
    private function writablePayload(array $payload): array
    {
        foreach (EmployeeTemplateSchema::READ_ONLY_FIELDS as $field) {
            unset($payload[$field]);
        }
        return $payload;
    }

    /**
     * Structured analysis for the preview endpoint.
     */
    public function getAnalysis(): array
    {
        return [
            'rows'    => $this->rows,
            'summary' => [
                'total'      => count($this->rows),
                'to_create'  => $this->countAction('create'),
                'to_update'  => $this->countAction('update'),
                'unchanged'  => $this->countAction('unchanged'),
                'errors'     => $this->countAction('error'),
                'salary_changes' => collect($this->rows)
                    ->filter(fn ($r) => array_key_exists('salary', $r['changes'] ?? []))
                    ->count(),
            ],
        ];
    }

    public function hasBlockingErrors(): bool
    {
        return $this->countAction('error') > 0;
    }

    private function countAction(string $action): int
    {
        return collect($this->rows)->where('action', $action)->count();
    }

    // --- value helpers -----------------------------------------------------

    private function diff(Employee $existing, array $payload): array
    {
        $changes = [];
        foreach ($payload as $key => $new) {
            if (in_array($key, EmployeeTemplateSchema::READ_ONLY_FIELDS, true)) {
                continue;
            }
            $old = $existing->getAttribute($key);
            // Normalize dates/arrays/scalars to comparable strings.
            if ($this->normalize($old) !== $this->normalize($new)) {
                $changes[$key] = ['from' => $this->normalize($old), 'to' => $this->normalize($new)];
            }
        }
        return $changes;
    }

    private function normalize($value): mixed
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('Y-m-d');
        }
        if (is_array($value)) {
            // Compare arrays by content only: drop null/empty entries and sort keys so that
            // {name, relationship, phone:null} equals {name, relationship}.
            $clean = array_filter($value, fn ($v) => $v !== null && $v !== '');
            if (empty($clean)) {
                return null;
            }
            ksort($clean);
            return json_encode($clean);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) (0 + $value); // 50000.0 === 50000
        }
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function emergencyContact(array $input): ?array
    {
        $contact = array_filter([
            'name'         => $input['emergency_contact_name'] ?: null,
            'relationship' => $input['emergency_contact_relationship'] ?: null,
            'phone'        => $input['emergency_contact_phone'] ?: null,
        ], fn ($v) => $v !== null);

        return empty($contact) ? null : $contact;
    }

    private function exemptions(?string $raw, array &$errors): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $items = collect(explode(',', $raw))
            ->map(fn ($v) => mb_strtolower(trim($v)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($items as $item) {
            if (! in_array($item, Employee::STATUTORY_EXEMPTIONS, true)) {
                $errors[] = "Invalid statutory exemption '{$item}'. Allowed: " . implode(', ', Employee::STATUTORY_EXEMPTIONS);
            }
        }
        return $items;
    }

    private function date(?string $raw, string $label, array &$errors): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);

        // Excel stores dates as serial numbers, and the default value binder converts
        // the exported 'Y-m-d' strings into date-typed cells. On reupload ToCollection
        // hands those back as bare serials (e.g. "45123"), which Carbon::parse would
        // silently misread as a year rather than a date — so decode serials explicitly.
        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
                $errors[] = "{$label} '{$raw}' is not a valid date (use YYYY-MM-DD).";
                return null;
            }
        }

        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable $e) {
            $errors[] = "{$label} '{$raw}' is not a valid date (use YYYY-MM-DD).";
            return null;
        }
    }

    private function bool($raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return in_array(mb_strtolower((string) $raw), ['1', 'yes', 'true', 'y'], true);
    }

    private function str($value): string
    {
        return $value === null ? '' : trim((string) $value);
    }

    private function isEmptyRow(array $input): bool
    {
        foreach ($input as $value) {
            if ($value !== '' && $value !== null) {
                return false;
            }
        }
        return true;
    }
}
