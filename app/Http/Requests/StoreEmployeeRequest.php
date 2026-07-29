<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'employee_code' => ['required', 'string', 'max:191', 'unique:employees,employee_code'],
            'biometric_emp_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employees', 'biometric_emp_code')
                    ->where(fn ($q) => $q->where('branch_id', $this->input('branch_id'))),
            ],
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_joining' => ['required', 'date'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'intern'])],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status'   => ['nullable', Rule::in(['active', 'inactive', 'terminated'])],
            'role'     => ['nullable', 'string', 'exists:roles,name'],
            // Login account creation
            'create_login' => ['nullable', 'boolean'],
            'password_option' => ['nullable', Rule::in(['auto', 'dob', 'manual'])],
            'password' => ['nullable', 'string', 'min:6'],
            'send_welcome_email' => ['nullable', 'boolean'],
        ] + self::profileRules();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('create_login')) {
                return;
            }

            $option = $this->input('password_option', 'auto');

            if ($option === 'manual' && ! $this->filled('password')) {
                $validator->errors()->add('password', 'A password is required when setting it manually.');
            }

            if ($option === 'dob' && ! $this->filled('date_of_birth')) {
                $validator->errors()->add('date_of_birth', 'Date of birth is required to generate a password from it.');
            }

            // The login account is created with the employee's email, so it
            // must not collide with any existing user's email.
            if ($this->filled('email') && \App\Models\User::where('email', $this->input('email'))->exists()) {
                $validator->errors()->add('email', 'This email is already used by another account. Use a different email, or turn off login creation for this employee.');
            }
        });
    }

    public static function profileRules(): array
    {
        return [
            'personal_email' => ['nullable', 'email', 'max:191'],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'emergency_contact_name' => ['nullable', 'string', 'max:191'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:191'],
            'bank_branch' => ['nullable', 'string', 'max:191'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'bank_ifsc_code' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cash', 'cheque'])],
            'probation_end_date' => ['nullable', 'date'],
            'date_of_leaving' => ['nullable', 'date'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'work_location' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
