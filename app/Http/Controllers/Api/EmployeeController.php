<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Mail\EmployeeWelcomeMail;
use App\Models\Employee;
use App\Models\User;
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

        $query = Employee::with(['department', 'designation', 'user', 'branch', 'media']);

        // Manager role: only see direct reports and self
        if ($user->hasRole('manager') && ! $user->hasAnyRole(['super_admin', 'branch_admin', 'hr'])) {
            $query->where(function ($q) use ($user) {
                $q->where('reporting_manager_id', $user->employee_id)
                  ->orWhere('id', $user->employee_id);
            });
        }

        // Optional additional filters
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 20), 200);
        $employees = $query->paginate($perPage);

        return response()->json([
            'data' => $employees->items(),
            'meta' => [
                'total'        => $employees->total(),
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $excluded = ['password', 'role', 'create_login', 'password_option', 'send_welcome_email'];

        try {
            [$employee, $credentials] = DB::transaction(function () use ($request, $excluded) {
                $employee = Employee::create($request->safe()->except($excluded));
                $credentials = null;

                if ($request->boolean('create_login')) {
                    // Always an employee login — system operator accounts are
                    // created in User Management, never from the employee form.
                    $role = $request->input('role') ?: 'employee';
                    if ($role === 'super_admin') {
                        $role = 'employee';
                    }

                    $plainPassword = $this->generatePassword($request, $employee);

                    $user = User::create([
                        'name'        => $employee->first_name . ' ' . $employee->last_name,
                        'email'       => $employee->email,
                        'password'    => Hash::make($plainPassword),
                        'user_type'   => 'employee',
                        'employee_id' => $employee->id,
                        'branch_id'   => $employee->branch_id,
                    ]);
                    $user->assignRole($role);
                    $employee->update(['user_id' => $user->id]);

                    $credentials = [
                        'email' => $employee->email,
                        'password' => $plainPassword,
                        'email_sent' => false,
                    ];
                }

                return [$employee->load(['department', 'designation', 'branch', 'user']), $credentials];
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Almost always the derived login email colliding with an existing
            // user (a concurrent request slipping past the pre-check above).
            // Never surface the raw SQL to the client.
            report($e);

            throw ValidationException::withMessages([
                'email' => 'This email is already used by another account. Use a different email, or turn off login creation for this employee.',
            ]);
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
            'data' => $employee,
            'credentials' => $credentials,
            'message' => 'Employee created successfully.',
        ], 201);
    }

    /**
     * 'dob' derives an initial password from the employee's date of birth
     * (DDMMYYYY) — a common HR convention for temporary credentials.
     * 'manual' uses the admin-supplied password. Anything else auto-generates
     * a random one; the employee is expected to change it after first login.
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
            'data' => $employee,
            'message' => 'Employee retrieved successfully.',
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $employee->update($request->validated());

        return response()->json([
            'data' => $employee->fresh(['department', 'designation', 'user', 'branch']),
            'message' => 'Employee updated successfully.',
        ]);
    }

    public function uploadAvatar(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $request->validate([
            'avatar' => ['required', 'image', 'max:4096'],
        ]);

        $employee->addMediaFromRequest('avatar')->toMediaCollection('avatar');

        return response()->json([
            'data' => $employee->fresh(['media']),
            'message' => 'Avatar updated successfully.',
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->update(['status' => 'terminated']);

        return response()->json([
            'data' => null,
            'message' => 'Employee terminated successfully.',
        ], 204);
    }
}
