<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Branch admins may only manage users within their own branch and may not
     * grant roles above their own. Super admins manage everything.
     */
    private function assertCanManage(Request $request, ?User $target = null): void
    {
        $actor = $request->user();

        if ($actor->is_super_admin || $actor->hasRole('super_admin')) {
            return;
        }

        if (! ($actor->hasRole('branch_admin') || $actor->can('users.manage'))) {
            abort(403, 'You are not allowed to manage users.');
        }

        if ($target && $target->branch_id !== $actor->branch_id) {
            abort(403, 'You can only manage users in your own branch.');
        }

        if ($target && ($target->is_super_admin || $target->hasRole('super_admin'))) {
            abort(403, 'You cannot manage a super admin account.');
        }
    }

    private function assertRoleAssignable(Request $request, ?string $role): void
    {
        if (! $role) {
            return;
        }

        $actor = $request->user();
        $isSuper = $actor->is_super_admin || $actor->hasRole('super_admin');

        if (! $isSuper && in_array($role, ['super_admin'], true)) {
            abort(403, 'Only a super admin can assign the super admin role.');
        }
    }

    /**
     * Keeps the two user bases from mixing: system accounts hold operator
     * roles and never link to an employee record; employee logins must link
     * to an employee and cannot be super admins.
     */
    private function assertTypeRoleConsistent(string $type, ?string $role, $employeeId): void
    {
        if ($type === 'system' && $role === 'employee') {
            abort(422, 'A system user cannot have the employee role. Create an employee login instead.');
        }

        if ($type === 'employee') {
            if ($role === 'super_admin') {
                abort(422, 'An employee login cannot be a super admin. Create a system user instead.');
            }
            if (! $employeeId) {
                abort(422, 'An employee login must be linked to an employee record.');
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        $actor = $request->user();
        $query = User::with(['roles:id,name', 'branch:id,name', 'employee:id,employee_code,first_name,last_name']);

        if (! ($actor->is_super_admin || $actor->hasRole('super_admin'))) {
            $query->where('branch_id', $actor->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('type')) {
            $query->where('user_type', $request->string('type'));
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20);

        return response()->json([
            'data' => collect($users->items())->map(fn ($u) => $this->present($u)),
            'meta' => [
                'total'        => $users->total(),
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
            ],
        ]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::orderBy('id')->pluck('name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanManage($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'user_type' => ['required', 'in:system,employee'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $this->assertRoleAssignable($request, $validated['role']);
        $this->assertTypeRoleConsistent($validated['user_type'], $validated['role'], $validated['employee_id'] ?? null);

        $actor = $request->user();
        if (! ($actor->is_super_admin || $actor->hasRole('super_admin'))) {
            $validated['branch_id'] = $actor->branch_id;
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'user_type' => $validated['user_type'],
            'branch_id' => $validated['branch_id'] ?? null,
            'employee_id' => $validated['user_type'] === 'employee' ? ($validated['employee_id'] ?? null) : null,
            'is_super_admin' => $validated['role'] === 'super_admin',
        ]);

        if ($validated['user_type'] === 'employee' && ! empty($validated['employee_id'])) {
            \App\Models\Employee::withoutGlobalScopes()
                ->where('id', $validated['employee_id'])
                ->update(['user_id' => $user->id]);
        }

        $user->assignRole($validated['role']);

        return response()->json([
            'data' => $this->present($user->load(['roles:id,name', 'branch:id,name', 'employee:id,employee_code,first_name,last_name'])),
            'message' => 'User created successfully.',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->assertCanManage($request, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $this->assertRoleAssignable($request, $validated['role'] ?? null);

        // user_type is immutable — the two bases must stay separate. Role
        // changes must stay consistent with the account's type.
        if (isset($validated['role'])) {
            $this->assertTypeRoleConsistent(
                $user->user_type,
                $validated['role'],
                $validated['employee_id'] ?? $user->employee_id
            );
        }

        if ($user->user_type === 'system') {
            unset($validated['employee_id']);
        }

        $actor = $request->user();
        $isSuper = $actor->is_super_admin || $actor->hasRole('super_admin');

        // A super admin cannot demote themselves — prevents locking everyone out.
        if (isset($validated['role']) && $user->id === $actor->id && $isSuper && $validated['role'] !== 'super_admin') {
            abort(422, 'You cannot remove your own super admin role.');
        }

        if (! $isSuper) {
            unset($validated['branch_id']);
        }

        $user->fill(collect($validated)->only(['name', 'email', 'employee_id'])->all());

        if (array_key_exists('branch_id', $validated)) {
            $user->branch_id = $validated['branch_id'];
        }

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
            $user->is_super_admin = $validated['role'] === 'super_admin';
        }

        $user->save();

        return response()->json([
            'data' => $this->present($user->fresh(['roles:id,name', 'branch:id,name', 'employee:id,employee_code,first_name,last_name'])),
            'message' => 'User updated successfully.',
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertCanManage($request, $user);

        if ($user->id === $request->user()->id) {
            abort(422, 'You cannot delete your own account.');
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'data' => null,
            'message' => 'User deleted successfully.',
        ]);
    }

    private function present(User $user): array
    {
        return array_merge($user->toArray(), [
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}
