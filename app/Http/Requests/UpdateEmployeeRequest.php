<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'employee_code' => [
                'sometimes',
                'string',
                'max:191',
                Rule::unique('employees', 'employee_code')->ignore($this->route('employee')),
            ],
            'biometric_emp_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employees', 'biometric_emp_code')
                    ->where(fn ($q) => $q->where('branch_id', $this->input('branch_id', $this->route('employee')?->branch_id)))
                    ->ignore($this->route('employee')),
            ],
            'first_name' => ['sometimes', 'string', 'max:191'],
            'last_name' => ['sometimes', 'string', 'max:191'],
            'email' => ['sometimes', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_joining' => ['sometimes', 'date'],
            'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contract', 'intern'])],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'terminated'])],
        ] + StoreEmployeeRequest::profileRules();
    }
}
