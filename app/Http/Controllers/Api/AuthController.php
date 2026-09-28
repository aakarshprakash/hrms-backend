<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterEmployeeRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access\Roles;
use App\Support\Access\SessionPayload;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->input('email')))->first();

        // Same message whether the email or the password is wrong.
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated. Contact your administrator.'],
            ]);
        }

        if ($user->company_id !== null) {
            $company = $this->context->withoutScoping(fn () => Company::find($user->company_id));

            if (! $company || ! $company->isActive()) {
                throw ValidationException::withMessages([
                    'email' => ['Your organisation\'s account is not active. Please contact support.'],
                ]);
            }
        }

        $this->context->set($user->company_id);
        $this->context->enforce();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();

        $expiresAt = ($minutes = config('sanctum.expiration')) ? now()->addMinutes((int) $minutes) : null;
        $token = $user->createToken($this->deviceName($request), ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'data' => array_merge(SessionPayload::for($user), [
                'token' => $token,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]),
            'message' => 'Login successful.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if (method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'data' => null,
            'message' => 'Logged out successfully.',
        ]);
    }

    /** Sign out of every device (e.g. after a lost phone). */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['data' => null, 'message' => 'Signed out of all devices.']);
    }

    public function me(Request $request): JsonResponse
    {
        $payload = SessionPayload::for($request->user());

        return response()->json([
            'data' => array_merge($payload, [
                // Legacy top-level keys.
                'roles' => $payload['user']['roles'],
                'permissions' => $payload['user']['permissions'],
            ]),
            'message' => 'Authenticated user retrieved.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        // Keep this session, end every other one.
        $currentId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return response()->json(['data' => null, 'message' => 'Password changed. Other devices have been signed out.']);
    }

    public function register(RegisterEmployeeRequest $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => strtolower($request->email),
                'password' => Hash::make($request->password),
                'branch_id' => $request->branch_id,
                'user_type' => 'employee',
            ]);

            $user->assignRole(Roles::EMPLOYEE);

            $employee = Employee::create([
                'branch_id' => $request->branch_id,
                'employee_code' => $request->employee_code,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'date_of_joining' => $request->date_of_joining,
                'employment_type' => $request->employment_type ?? 'full_time',
                'user_id' => $user->id,
            ]);

            $user->update(['employee_id' => $employee->id]);

            return ['user' => $user->fresh(), 'employee' => $employee];
        });

        return response()->json([
            'data' => $result,
            'message' => 'Employee registered successfully.',
        ], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Always the same answer, so the endpoint can't be used to discover
        // which emails have accounts.
        Password::sendResetLink(['email' => strtolower($request->input('email'))]);

        return response()->json([
            'data' => null,
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    'must_change_password' => false,
                ])->save();

                // A reset means the old password may be compromised.
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'data' => null,
                'message' => __($status),
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    private function deviceName(Request $request): string
    {
        $agent = (string) $request->userAgent();

        return mb_substr($request->input('device_name') ?: ($agent ?: 'api-token'), 0, 120);
    }
}
