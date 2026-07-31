<?php

namespace App\Modules\HR\Http\Requests;

use App\Constants\Permissions;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already enforces EMPLOYEE_UPDATE; FormRequest authorize() is the
        // canonical authz hook so non-HTTP callers (tests, artisan) are covered too.
        $employee = $this->route('employee');

        // Must have the update permission AND the record must be in-scope for this user.
        if (! $this->user()?->can(Permissions::EMPLOYEE_UPDATE)) {
            return false;
        }

        return $employee ? $employee->isAccessibleBy($this->user()) : false;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $id       = $employee?->id;

        return [
            'employee_id'          => ['sometimes', Rule::unique('employees')->ignore($id)],
            'hikvision_id'         => ['nullable', 'string', 'max:50', Rule::unique('employees')->ignore($id)],
            'first_name'           => 'sometimes|required|string|max:255',
            'last_name'            => 'sometimes|required|string|max:255',
            'email'                => ['sometimes', 'nullable', 'email', Rule::unique('employees')->ignore($id)],
            'phone'                => 'nullable|string|max:20',
            'gender'               => ['nullable', Rule::in(['male', 'female'])],
            'department_id'        => 'sometimes|required|exists:departments,id',
            'position'             => 'sometimes|required|string|max:255',
            'hire_date'            => 'sometimes|required|date',
            'salary'               => 'nullable|numeric|min:0',

            // Regulated PII — ignore own record for uniqueness.
            'id_number'            => ['nullable', 'string', 'max:20', Rule::unique('employees')->ignore($id)],
            'kra_pin'              => ['nullable', 'string', 'max:20', Rule::unique('employees')->ignore($id)],
            'nssf_id'              => ['nullable', 'string', 'max:20', Rule::unique('employees')->ignore($id)],
            'nhif_id'              => ['nullable', 'string', 'max:20', Rule::unique('employees')->ignore($id)],

            'bank_name'            => 'nullable|string|max:255',
            'bank_branch'          => 'nullable|string|max:255',
            'bank_code'            => 'nullable|string|max:20',
            'account_number'       => 'nullable|string|max:50',
            'payment_method'       => ['nullable', Rule::in(['bank', 'mpesa', 'mobile_money', 'cash', 'cheque'])],
            'statutory_exemptions' => 'nullable|array',
            'statutory_exemptions.*' => ['string', Rule::in(Employee::STATUTORY_EXEMPTIONS)],

            'date_of_birth'        => 'nullable|date',
            'probation_end_date'   => 'nullable|date',
            'is_on_probation'      => 'nullable|boolean',
            'contract_end_date'    => 'nullable|date',

            'status'               => ['sometimes', 'required', Rule::in(['active', 'inactive', 'terminated', 'on-leave', 'suspended'])],
            'employment_type'      => ['nullable', Rule::in(['full-time', 'part-time', 'contract', 'intern'])],

            // manager_id != self — prevents org-chart cycles.
            'manager_id'           => ['nullable', 'exists:employees,id', Rule::notIn([$id])],

            'address'              => 'nullable|string',
            'emergency_contact'    => 'nullable|array',
            'performance_rating'   => 'nullable|numeric|min:0|max:5',
            'last_review_date'     => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'manager_id.not_in' => 'An employee cannot be their own manager.',
        ];
    }

    public function withValidator($validator): void
    {
        $employee = $this->route('employee');

        $validator->after(function ($v) use ($employee) {
            if (! $employee) {
                return;
            }

            // Date ordering: probation_end / contract_end must be after hire_date.
            $hireDate = $this->input('hire_date', $employee->hire_date?->toDateString());

            if ($hireDate && $this->filled('probation_end_date')) {
                if ($this->input('probation_end_date') < $hireDate) {
                    $v->errors()->add('probation_end_date', 'Probation end date must be on or after hire date.');
                }
            }

            if ($hireDate && $this->filled('contract_end_date')) {
                if ($this->input('contract_end_date') < $hireDate) {
                    $v->errors()->add('contract_end_date', 'Contract end date must be on or after hire date.');
                }
            }

            if ($hireDate && $this->filled('date_of_birth')) {
                if ($this->input('date_of_birth') >= $hireDate) {
                    $v->errors()->add('date_of_birth', 'Date of birth must be before hire date.');
                }
            }
        });
    }
}
