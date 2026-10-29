<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Employees\EmployeeCodes;
use App\Support\Tenancy\TenantContext;
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
            // Blank is fine when the organisation numbers employees automatically.
            'employee_code' => [Rule::requiredIf(! app(EmployeeCodes::class)->settings(app(TenantContext::class)->company())['auto']), 'nullable', 'string', 'max:191',
                Rule::unique('employees', 'employee_code')->where('company_id', app(TenantContext::class)->id())],
            'biometric_emp_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employees', 'biometric_emp_code')
                    ->where(fn ($q) => $q->where('branch_id', $this->input('branch_id'))),
            ],
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['nullable', 'string', 'max:191'],
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
            // Login emails are unique platform-wide, so check every tenant.
            if ($this->filled('email') && \Illuminate\Support\Facades\DB::table('users')->where('email', $this->input('email'))->exists()) {
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
            'work_location_id' => ['nullable', 'integer', Rule::exists('work_locations', 'id')->where('company_id', app(TenantContext::class)->id())],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Attendance & statutory
            'weekly_off_days' => ['nullable', 'array'],
            'weekly_off_days.*' => ['integer', 'between:0,6'],
            // Geo-fencing: branch sites (on), anywhere (off: sales / travelling staff), or assigned sites.
            'geofence_mode' => ['sometimes', Rule::in(['branch', 'anywhere', 'sites'])],
            'uan' => ['nullable', 'digits:12'],
            'pf_number' => ['nullable', 'string', 'max:30'],
            'esi_number' => ['nullable', 'string', 'max:20'],
            'pf_opted_out' => ['sometimes', 'boolean'],
            'pt_exempt' => ['sometimes', 'boolean'],
            'tax_regime' => ['sometimes', Rule::in(['new', 'old'])],
            'declared_deductions' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
        ];
    }
}
