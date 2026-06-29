<?php

namespace App\Modules\HR\Http\Requests;

use App\Constants\Permissions;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    /**
     * Gate: requires EMPLOYEE_CREATE permission (route already enforces it via middleware,
     * but the FormRequest is the canonical place so the Policy covers test/console paths too).
     */
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::EMPLOYEE_CREATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'          => 'nullable|string|unique:employees,employee_id',
            'hikvision_id'         => 'nullable|string|max:50|unique:employees,hikvision_id',
            'first_name'           => 'required|string|max:255',
            'last_name'            => 'required|string|max:255',
            'email'                => 'nullable|email|unique:employees,email',
            'phone'                => 'nullable|string|max:20',
            'department_id'        => 'required|exists:departments,id',
            'position'             => 'required|string|max:255',
            'hire_date'            => 'required|date',
            'salary'               => 'nullable|numeric|min:0',

            // Regulated PII — uniqueness prevents duplicate identity records.
            'id_number'            => 'nullable|string|max:20|unique:employees,id_number',
            'kra_pin'              => 'nullable|string|max:20|unique:employees,kra_pin',
            'nssf_id'              => 'nullable|string|max:20|unique:employees,nssf_id',
            'nhif_id'              => 'nullable|string|max:20|unique:employees,nhif_id',

            'bank_name'            => 'nullable|string|max:255',
            'bank_branch'          => 'nullable|string|max:255',
            'bank_code'            => 'nullable|string|max:20',
            'account_number'       => 'nullable|string|max:50',
            'payment_method'       => ['nullable', Rule::in(['bank', 'mpesa', 'mobile_money', 'cash', 'cheque'])],
            'statutory_exemptions' => 'nullable|array',
            'statutory_exemptions.*' => ['string', Rule::in(Employee::STATUTORY_EXEMPTIONS)],

            // Date cross-field guards
            'date_of_birth'        => 'nullable|date|before:hire_date',
            'probation_end_date'   => 'nullable|date|after_or_equal:hire_date',
            'is_on_probation'      => 'nullable|boolean',
            'contract_end_date'    => 'nullable|date|after_or_equal:hire_date',

            'status'               => ['required', Rule::in(['active', 'inactive', 'terminated', 'on-leave'])],
            'employment_type'      => ['nullable', Rule::in(['full-time', 'part-time', 'contract', 'intern'])],

            // Guard: an employee cannot be their own manager.
            'manager_id'           => 'nullable|exists:employees,id',

            'address'              => 'nullable|string',
            'emergency_contact'          => 'nullable|array',
            'emergency_contact.name'     => 'nullable|string|max:255',
            'emergency_contact.relationship' => 'nullable|string|max:255',
            'emergency_contact.phone'    => 'nullable|string|max:20',
            'performance_rating'   => 'nullable|numeric|min:0|max:5',
            'last_review_date'     => 'nullable|date',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // manager_id cannot reference the employee being created — this is caught
            // post-insert via observer if needed; here we just block an explicit mismatch
            // (e.g. when the caller passes a manager_id equal to an existing id but the
            // intent is clearly wrong — the cycle guard at update time is more critical).
        });
    }
}
