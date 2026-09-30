<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\Access\RoleGrants;
use App\Support\Access\Roles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * User accounts within the acting organisation. Guards against privilege
 * escalation: an admin who isn't the tenant admin can only manage users in
 * their own branches, and can only grant roles that are no broader than
 * their own access (permissions and data scope).
 */
class UserController extends Controller
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = User::with(['roles:id,name,display_name,data_scope', 'branch:id,name', 'extraBranches:id,name', 'employee:id,employee_code,first_name,last_name']);

        $this->scopeToManageable($query, $actor);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $this->requestedBranchId());
        }

        if ($request->filled('type')) {
            $query->where('user_type', $request->string('type'));
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(min($request->integer('per_page', 20), 100));

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

    /** Roles the actor may assign. `data` stays a list of names for older clients. */
    public function roles(Request $request): JsonResponse
    {
        $roles = Role::availableTo($this->context->id())
            ->orderBy('is_system', 'desc')->orderBy('id')
            ->get()
            ->filter(fn (Role $role) => RoleGrants::canGrant($request->user(), $role))
            ->values();

        return response()->json([
            'data' => $roles->pluck('name'),
            'options' => $roles->map(fn (Role $r) => [
                'name' => $r->name, 'label' => $r->label, 'data_scope' => $r->effectiveDataScope(), 'is_system' => $r->is_system,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'role' => ['required', 'string', 'exists:roles,name'],
            'user_type' => ['required', 'in:system,employee'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'extra_branch_ids' => ['nullable', 'array'],
            'extra_branch_ids.*' => ['integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'must_change_password' => ['sometimes', 'boolean'],
        ]);

        $role = RoleGrants::assignable($actor, $validated['role']);
        $this->assertTypeRoleConsistent($validated['user_type'], $role->name, $validated['employee_id'] ?? null);
        $validated['branch_id'] = $this->resolveBranch($actor, $validated['branch_id'] ?? null);
        $this->assertBranchesAssignable($actor, $validated['extra_branch_ids'] ?? []);

        $user = DB::transaction(function () use ($validated, $role) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'user_type' => $validated['user_type'],
                'branch_id' => $validated['branch_id'],
                'employee_id' => $validated['user_type'] === 'employee' ? ($validated['employee_id'] ?? null) : null,
                'must_change_password' => $validated['must_change_password'] ?? true,
            ]);

            if ($user->employee_id) {
                Employee::withoutGlobalScope(BranchScope::class)->whereKey($user->employee_id)->update(['user_id' => $user->id]);
            }

            $user->assignRole($role);
            $user->extraBranches()->sync($this->pivot($validated['extra_branch_ids'] ?? []));

            return $user;
        });

        return response()->json([
            'data' => $this->present($user->load(['roles', 'branch:id,name', 'extraBranches:id,name', 'employee:id,employee_code,first_name,last_name'])),
            'message' => 'User created successfully.',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->assertCanManage($actor, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', Password::min(8)->letters()->numbers()],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'extra_branch_ids' => ['nullable', 'array'],
            'extra_branch_ids.*' => ['integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $role = isset($validated['role']) ? RoleGrants::assignable($actor, $validated['role']) : null;

        if ($role) {
            // user_type is immutable -- the two user bases stay separate.
            $this->assertTypeRoleConsistent($user->user_type, $role->name, $validated['employee_id'] ?? $user->employee_id);

            if ($user->id === $actor->id && $user->isTenantAdmin() && $role->name !== Roles::TENANT_ADMIN) {
                abort(422, 'You cannot remove your own tenant admin role.');
            }
            if ($user->isTenantAdmin() && $role->name !== Roles::TENANT_ADMIN) {
                $this->assertNotLastTenantAdmin($user);
            }
        }

        if (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            abort_if($user->id === $actor->id, 422, 'You cannot deactivate your own account.');
            if ($user->isTenantAdmin()) {
                $this->assertNotLastTenantAdmin($user);
            }
        }

        if (array_key_exists('branch_id', $validated)) {
            $validated['branch_id'] = $this->resolveBranch($actor, $validated['branch_id']);
        }
        if (array_key_exists('extra_branch_ids', $validated)) {
            $this->assertBranchesAssignable($actor, $validated['extra_branch_ids'] ?? []);
        }
        if ($user->user_type === 'system') {
            unset($validated['employee_id']);
        }

        DB::transaction(function () use ($user, $validated, $role) {
            $user->fill(collect($validated)->only(['name', 'email', 'phone', 'employee_id', 'is_active'])->all());

            if (array_key_exists('branch_id', $validated)) {
                $user->branch_id = $validated['branch_id'];
            }

            if (! empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
                $user->password_changed_at = now();
                $user->must_change_password = true;
            }

            $user->save();

            if ($role && ! $user->hasRole($role->name)) {
                $previous = $user->getRoleNames()->all();
                $user->syncRoles([$role]);
                activity('security')->performedOn($user)->event('role_changed')
                    ->withProperties(['old' => ['roles' => $previous], 'attributes' => ['roles' => [$role->name]]])
                    ->log('Role changed');
            }
            if (array_key_exists('extra_branch_ids', $validated)) {
                $user->extraBranches()->sync($this->pivot($validated['extra_branch_ids'] ?? []));
            }

            // A deactivated account or a reset password ends every session.
            if (($user->wasChanged('is_active') && ! $user->is_active) || ! empty($validated['password'])) {
                $user->tokens()->delete();
            }
        });

        return response()->json([
            'data' => $this->present($user->fresh(['roles', 'branch:id,name', 'extraBranches:id,name', 'employee:id,employee_code,first_name,last_name'])),
            'message' => 'User updated successfully.',
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->assertCanManage($actor, $user);

        abort_if($user->id === $actor->id, 422, 'You cannot delete your own account.');

        if ($user->isTenantAdmin()) {
            $this->assertNotLastTenantAdmin($user);
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            Employee::withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->update(['user_id' => null]);
            $user->delete();
        });

        return response()->json([
            'data' => null,
            'message' => 'User deleted successfully.',
        ]);
    }

    // ── Guards ────────────────────────────────────────────────────────────

    private function scopeToManageable($query, User $actor): void
    {
        $branchIds = $actor->accessibleBranchIds();

        if ($branchIds !== null) {
            $query->where(function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->orWhereHas('extraBranches', fn ($b) => $b->whereIn('branches.id', $branchIds));
            });
        }
    }

    private function assertCanManage(User $actor, User $target): void
    {
        $query = User::whereKey($target->id);
        $this->scopeToManageable($query, $actor);

        abort_unless($query->exists(), 404, 'User not found.');

        if (! $actor->isTenantAdmin() && ! $actor->isPlatformAdmin() && $target->isTenantAdmin()) {
            abort(403, 'Only a tenant admin can manage another tenant admin.');
        }
    }

    private function resolveBranch(User $actor, ?int $branchId): ?int
    {
        $allowed = $actor->accessibleBranchIds();

        if ($allowed === null) {
            return $branchId;
        }

        // Branch-bound admins can only create users inside their own branches.
        $branchId ??= $allowed[0];
        abort_unless(in_array($branchId, $allowed, true), 403, 'You can only manage users in your own branch.');

        return $branchId;
    }

    private function assertBranchesAssignable(User $actor, array $branchIds): void
    {
        foreach ($branchIds as $branchId) {
            $this->authorizeBranch((int) $branchId, 'You can only grant access to your own branches.');
        }
    }

    private function assertNotLastTenantAdmin(User $user): void
    {
        $admins = User::role(Roles::TENANT_ADMIN)->where('is_active', true)->count();

        abort_if($admins <= 1, 422, 'An organisation must keep at least one active tenant admin.');
    }

    /**
     * Keeps the two user bases from mixing: system accounts hold operator
     * roles and never link to an employee record; employee logins must link
     * to an employee.
     */
    private function assertTypeRoleConsistent(string $type, string $role, $employeeId): void
    {
        if ($type === 'system' && $role === Roles::EMPLOYEE) {
            abort(422, 'A system user cannot have the employee role. Create an employee login instead.');
        }

        if ($type === 'employee' && ! $employeeId) {
            abort(422, 'An employee login must be linked to an employee record.');
        }
    }

    private function pivot(array $branchIds): array
    {
        $companyId = $this->context->id();

        return collect($branchIds)->mapWithKeys(fn ($id) => [(int) $id => ['company_id' => $companyId]])->all();
    }

    private function present(User $user): array
    {
        return array_merge($user->toArray(), [
            'roles' => $user->getRoleNames(),
            'role_labels' => $user->roles->map(fn ($r) => $r->label)->values(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'data_scope' => $user->dataScope(),
        ]);
    }
}
