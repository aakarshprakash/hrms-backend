<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Mail\EmployeeWelcomeMail;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access\RoleGrants;
use App\Support\Access\Roles;
use App\Support\Billing\Limits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $user = $request->user();

        $query = Employee::with(['department', 'designation', 'user', 'branch', 'media'])->visibleTo($user);

        if ($branchId = $this->requestedBranchId()) {
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('designation_id')) {
            $query->where('designation_id', $request->integer('designation_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);
        $employees = $query->orderBy('first_name')->paginate($perPage);

        // Rows are already within the user's scope, so one permission check covers them all.
        $canSeeSensitive = $user->can('employees.sensitive');

        return response()->json([
            'data' => collect($employees->items())->map(
                fn (Employee $e) => ($canSeeSensitive || $e->id === $user->employee_id) ? $e : $e->maskSensitive()
            ),
            'meta' => [
                'total'        => $employees->total(),
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request, TenantContext $context): JsonResponse
    {
        $this->authorize('create', Employee::class);
        $this->authorizeBranch($request->integer('branch_id'));

        if ($request->input('status', 'active') === 'active') {
            Limits::assertCanAdd($context->company(), 'employees');
        }

        $excluded = ['password', 'role', 'create_login', 'password_option', 'send_welcome_email'];
        $attributes = $request->safe()->except($excluded);
        $attributes = $this->withoutUnauthorizedSensitive($request->user(), null, $attributes);

        // Resolve (and authorize) the login role before writing anything.
        $role = $request->boolean('create_login')
            ? RoleGrants::assignable($request->user(), $request->input('role') ?: Roles::EMPLOYEE)
            : null;

        try {
            [$employee, $credentials] = DB::transaction(function () use ($request, $attributes, $role) {
                $employee = Employee::create($attributes);
                $credentials = null;

                if ($role) {
                    $plainPassword = $this->generatePassword($request, $employee);

                    $user = User::create([
                        'name'        => $employee->full_name,
                        'email'       => strtolower($employee->email),
                        'phone'       => $employee->phone,
                        'password'    => Hash::make($plainPassword),
                        'user_type'   => 'employee',
                        'employee_id' => $employee->id,
                        'branch_id'   => $employee->branch_id,
                        'must_change_password' => true,
                    ]);
                    $user->assignRole($role);
                    $employee->update(['user_id' => $user->id]);

                    $credentials = [
                        'email' => $user->email,
                        'password' => $plainPassword,
                        'email_sent' => false,
                    ];
                }

                return [$employee->load(['department', 'designation', 'branch', 'user']), $credentials];
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Almost always the login email colliding with an existing user
            // (a concurrent request slipping past the pre-check). Never
            // surface the raw SQL to the client.
            report($e);

            throw ValidationException::withMessages([
                'email' => 'This email is already used by another account. Use a different email, or turn off login creation for this employee.',
            ]);
        }

        // Opening leave balances by policy (the nightly run would do it too).
        try {
            app(\App\Services\Leave\LeaveAccrualService::class)
                ->syncEmployee($employee, \Carbon\CarbonImmutable::now($employee->branch?->timezone ?: config('app.timezone')));
        } catch (\Throwable $e) {
            report($e);
        }

        if ($credentials && $request->boolean('send_welcome_email', true)) {
            try {
                Mail::to($credentials['email'])->send(new EmployeeWelcomeMail($employee, $credentials['password']));
                $credentials['email_sent'] = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'data' => $this->present($employee),
            'credentials' => $credentials,
            'message' => 'Employee created successfully.',
        ], 201);
    }

    /**
     * 'dob' derives an initial password from the employee's date of birth
     * (DDMMYYYY) — a common HR convention for temporary credentials.
     * 'manual' uses the admin-supplied password. Anything else auto-generates
     * a random one. Either way the employee must change it at first login.
     */
    private function generatePassword(Request $request, Employee $employee): string
    {
        $option = $request->input('password_option', 'auto');

        return match ($option) {
            'dob' => $employee->date_of_birth->format('dmY'),
            'manual' => (string) $request->input('password'),
            default => Str::password(10, letters: true, numbers: true, symbols: false, spaces: false),
        };
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->load(['department', 'designation', 'user', 'branch', 'reportingManager', 'directReports']);

        return response()->json([
            'data' => $this->present($employee),
            'message' => 'Employee retrieved successfully.',
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $attributes = $request->validated();

        if (isset($attributes['branch_id']) && (int) $attributes['branch_id'] !== $employee->branch_id) {
            $this->authorizeBranch((int) $attributes['branch_id'], 'You cannot move an employee to a branch you do not manage.');
        }

        if (($attributes['reporting_manager_id'] ?? null) === $employee->id) {
            throw ValidationException::withMessages(['reporting_manager_id' => 'An employee cannot report to themselves.']);
        }

        $attributes = $this->withoutUnauthorizedSensitive($request->user(), $employee, $attributes);

        $employee->update($attributes);

        // A newly mapped device code pulls in punches recorded before the mapping.
        if ($employee->wasChanged('biometric_emp_code') && $employee->biometric_emp_code) {
            $dates = app(\App\Services\Attendance\PunchRecorder::class)->relinkUnmatched($employee);
            if ($dates) {
                app(\App\Services\Attendance\AttendanceProcessor::class)->processAffected([$employee->id => $dates]);
            }
        }

        return response()->json([
            'data' => $this->present($employee->fresh(['department', 'designation', 'user', 'branch'])),
            'message' => 'Employee updated successfully.',
        ]);
    }

    public function uploadAvatar(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('updateAvatar', $employee);

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:4096'],
        ]);

        $employee->addMediaFromRequest('avatar')->toMediaCollection('avatar');

        return response()->json([
            'data' => $this->present($employee->fresh(['media'])),
            'message' => 'Avatar updated successfully.',
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        DB::transaction(function () use ($employee) {
            $employee->update(['status' => 'terminated', 'date_of_leaving' => $employee->date_of_leaving ?? now()->toDateString()]);

            // A terminated employee's login stops working immediately.
            if ($employee->user) {
                $employee->user->forceFill(['is_active' => false])->save();
                $employee->user->tokens()->delete();
            }
        });

        return response()->json([
            'data' => null,
            'message' => 'Employee terminated successfully.',
        ], 204);
    }

    /** Mask bank / PAN / Aadhaar unless the viewer may see them. */
    private function present(Employee $employee): Employee
    {
        if (! request()->user()->can('viewSensitive', $employee)) {
            $employee->maskSensitive();
        }

        return $employee;
    }

    /** Sensitive fields are silently dropped from a write the user isn't cleared for. */
    private function withoutUnauthorizedSensitive(User $user, ?Employee $employee, array $attributes): array
    {
        $allowed = $employee ? $user->can('viewSensitive', $employee) : $user->can('employees.sensitive');

        return $allowed ? $attributes : array_diff_key($attributes, array_flip(Employee::SENSITIVE));
    }
}
