<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Access\PermissionCatalog;
use App\Support\Access\Roles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role management for one organisation.
 *
 * Built-in roles are shared by every tenant, so a tenant can't rename,
 * delete or re-permission them (that would change every other tenant's
 * access). Tenants tailor access by creating their own roles: those belong
 * to the tenant (company_id), carry a data scope (company / branch / team /
 * self) and any permissions from the catalog. Internally a custom role's
 * name is prefixed with the tenant id ("t12_accountant") so names never
 * collide across tenants; `label` is what users see.
 */
class RoleController extends Controller
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function index(): JsonResponse
    {
        $roles = Role::availableTo($this->context->id())
            ->with('permissions:id,name')
            ->orderByDesc('is_system')->orderBy('id')
            ->get();

        // Only count this organisation's users (model_has_roles is global).
        $userCounts = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('users.company_id', $this->context->id())
            ->select('role_id', DB::raw('COUNT(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return response()->json(['data' => $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'data_scope' => $role->effectiveDataScope(),
            'is_system' => (bool) $role->is_system,
            'is_locked' => (bool) $role->is_system,
            'all_permissions' => $role->name === Roles::TENANT_ADMIN,
            'users_count' => (int) ($userCounts[$role->id] ?? 0),
            'permissions' => $role->name === Roles::TENANT_ADMIN
                ? PermissionCatalog::all()
                : $role->permissions->pluck('name'),
        ])]);
    }

    public function permissions(): JsonResponse
    {
        $groups = [];
        foreach (PermissionCatalog::CATALOG as $group => $permissions) {
            $groups[] = [
                'group' => $group,
                'permissions' => collect($permissions)
                    ->map(fn ($description, $name) => ['name' => $name, 'description' => $description])
                    ->values(),
            ];
        }

        return response()->json([
            'data' => $groups,
            'data_scopes' => [
                ['value' => Roles::SCOPE_COMPANY, 'label' => 'Whole organisation'],
                ['value' => Roles::SCOPE_BRANCH, 'label' => 'Assigned branch(es)'],
                ['value' => Roles::SCOPE_TEAM, 'label' => 'Reporting team'],
                ['value' => Roles::SCOPE_SELF, 'label' => 'Own records only'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateRole($request);
        $companyId = $this->context->id();
        abort_unless($companyId, 403, 'No organisation selected.');

        $name = $this->internalName($validated['name']);

        if (Role::where('name', $name)->exists() || $this->labelTaken($validated['name'])) {
            abort(422, "A role named \"{$validated['name']}\" already exists.");
        }

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->forceFill([
            'company_id' => $companyId,
            'display_name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'data_scope' => $validated['data_scope'] ?? Roles::SCOPE_BRANCH,
            'is_system' => false,
        ])->save();
        $role->syncPermissions($validated['permissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => $role->load('permissions:id,name'),
            'message' => 'Role created successfully.',
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->assertTenantOwned($role);
        $validated = $this->validateRole($request, updating: true);

        if (isset($validated['name']) && $validated['name'] !== $role->display_name) {
            if ($this->labelTaken($validated['name'], $role->id)) {
                abort(422, "A role named \"{$validated['name']}\" already exists.");
            }
            $role->display_name = $validated['name'];
        }

        foreach (['description', 'data_scope'] as $key) {
            if (array_key_exists($key, $validated)) {
                $role->{$key} = $validated[$key];
            }
        }
        $role->save();

        if (array_key_exists('permissions', $validated)) {
            $before = $role->permissions->pluck('name')->sort()->values()->all();
            $role->syncPermissions($validated['permissions'] ?? []);
            $after = collect($validated['permissions'] ?? [])->sort()->values()->all();

            if ($before !== $after) {
                activity('security')->performedOn($role)->event('permissions_changed')
                    ->withProperties(['old' => ['permissions' => $before], 'attributes' => ['permissions' => $after]])
                    ->log('Role permissions changed');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => $role->fresh(['permissions']),
            'message' => 'Role updated successfully.',
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->assertTenantOwned($role);

        if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            abort(422, 'This role is still assigned to users. Reassign them first.');
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => null,
            'message' => 'Role deleted successfully.',
        ]);
    }

    private function validateRole(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:50', 'regex:/^[\pL\pN][\pL\pN\s\-_&\/]*$/u'],
            'description' => ['nullable', 'string', 'max:255'],
            'data_scope' => ['nullable', Rule::in(array_keys(Roles::SCOPE_RANK))],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ]);
    }

    /** Built-in roles are shared across tenants and therefore read-only here. */
    private function assertTenantOwned(Role $role): void
    {
        abort_if($role->is_system || $role->company_id === null, 422,
            'Built-in roles are shared by all organisations and cannot be changed. Create a custom role instead.');
        abort_unless($role->company_id === $this->context->id(), 404);
    }

    private function internalName(string $label): string
    {
        $slug = Str::of($label)->lower()->snake()->replaceMatches('/[^a-z0-9_]/', '')->limit(40, '')->toString() ?: 'role';

        return 't' . $this->context->id() . '_' . $slug;
    }

    private function labelTaken(string $label, ?int $exceptId = null): bool
    {
        return Role::availableTo($this->context->id())
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get()
            ->contains(fn (Role $r) => strcasecmp($r->label, $label) === 0);
    }
}
