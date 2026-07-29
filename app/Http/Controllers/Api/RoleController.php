<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dynamic role management. Built-in roles are protected: super_admin is
 * untouchable, the other defaults can have their permissions tuned but cannot
 * be renamed or deleted. Custom roles are fully manageable.
 */
class RoleController extends Controller
{
    private const PROTECTED_ROLES = ['super_admin', 'branch_admin', 'hr', 'manager', 'employee'];

    public function index(): JsonResponse
    {
        $userCounts = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->select('role_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $roles = Role::with('permissions:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => in_array($role->name, self::PROTECTED_ROLES, true),
                'is_locked' => $role->name === 'super_admin',
                'users_count' => (int) ($userCounts[$role->id] ?? 0),
                'permissions' => $role->permissions->pluck('name'),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function permissions(): JsonResponse
    {
        $groups = [];
        foreach (PermissionCatalogSeeder::CATALOG as $group => $permissions) {
            $groups[] = [
                'group' => $group,
                'permissions' => collect($permissions)
                    ->map(fn ($description, $name) => ['name' => $name, 'description' => $description])
                    ->values(),
            ];
        }

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $name = Str::of($validated['name'])->lower()->snake()->toString();

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            abort(422, 'Role name may only contain letters, numbers and underscores.');
        }

        if (Role::where('name', $name)->exists()) {
            abort(422, "A role named \"{$name}\" already exists.");
        }

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => $role->load('permissions:id,name'),
            'message' => 'Role created successfully.',
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'super_admin') {
            abort(422, 'The super admin role cannot be modified.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if (isset($validated['name']) && ! in_array($role->name, self::PROTECTED_ROLES, true)) {
            $name = Str::of($validated['name'])->lower()->snake()->toString();

            if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                abort(422, 'Role name may only contain letters, numbers and underscores.');
            }

            if ($name !== $role->name && Role::where('name', $name)->exists()) {
                abort(422, "A role named \"{$name}\" already exists.");
            }

            $role->update(['name' => $name]);
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions'] ?? []);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => $role->fresh(['permissions']),
            'message' => 'Role updated successfully.',
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            abort(422, 'Built-in roles cannot be deleted.');
        }

        $assigned = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->count();

        if ($assigned > 0) {
            abort(422, 'This role is still assigned to users. Reassign them first.');
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => null,
            'message' => 'Role deleted successfully.',
        ]);
    }
}
