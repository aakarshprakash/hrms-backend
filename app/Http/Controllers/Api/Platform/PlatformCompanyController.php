<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Tenancy\IndustryTemplateService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Platform operator (super admin) console: every tenant, their usage and
 * lifecycle. Reads span tenants, so each action runs with tenant scoping
 * explicitly lifted -- this controller is only reachable with the
 * platform.manage ability (see routes/api.php).
 */
class PlatformCompanyController extends Controller
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function stats(): JsonResponse
    {
        return $this->context->withoutScoping(function () {
            $byStatus = Company::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');

            return response()->json(['data' => [
                'companies' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[Company::STATUS_ACTIVE] ?? 0),
                'suspended' => (int) ($byStatus[Company::STATUS_SUSPENDED] ?? 0),
                'cancelled' => (int) ($byStatus[Company::STATUS_CANCELLED] ?? 0),
                'employees' => DB::table('employees')->where('status', 'active')->count(),
                'users' => DB::table('users')->whereNotNull('company_id')->count(),
                'new_this_month' => Company::where('created_at', '>=', now()->startOfMonth())->count(),
                'by_industry' => Company::select('industry', DB::raw('COUNT(*) as total'))->groupBy('industry')->pluck('total', 'industry'),
            ]]);
        });
    }

    public function industries(): JsonResponse
    {
        return response()->json([
            'data' => collect(Company::INDUSTRIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'templates' => IndustryTemplateService::available(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($request) {
            $query = Company::query()
                ->withCount([
                    'branches',
                    'employees as active_employees_count' => fn ($q) => $q->where('status', 'active'),
                    'users',
                ]);

            if ($request->filled('search')) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            }

            foreach (['status', 'industry'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->string($filter));
                }
            }

            $companies = $query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 25), 100));

            return response()->json([
                'data' => $companies->items(),
                'meta' => [
                    'total' => $companies->total(),
                    'current_page' => $companies->currentPage(),
                    'last_page' => $companies->lastPage(),
                    'per_page' => $companies->perPage(),
                ],
            ]);
        });
    }

    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'industry' => ['required', Rule::in(array_keys(Company::INDUSTRIES))],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'timezone:all'],
            'branch_name' => ['nullable', 'string', 'max:191'],
            'apply_template' => ['sometimes', 'boolean'],
            'plan_code' => ['nullable', 'string', 'max:40'],
            'admin_name' => ['required', 'string', 'max:191'],
            'admin_email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'admin_phone' => ['nullable', 'string', 'max:30'],
            'admin_password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ]);

        $result = $provisioner->provision($validated);

        return response()->json([
            'data' => [
                'company' => $result['company'],
                'branch' => $result['branch'],
                'admin' => $result['admin']->only(['id', 'name', 'email']),
            ],
            'message' => 'Organisation created.',
        ], 201);
    }

    public function show(int $company): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($company) {
            $model = Company::withCount([
                'branches',
                'employees as active_employees_count' => fn ($q) => $q->where('status', 'active'),
                'users',
            ])->findOrFail($company);

            $admins = DB::table('users')
                ->join('model_has_roles', function ($j) {
                    $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', \App\Models\User::class);
                })
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('users.company_id', $model->id)
                ->where('roles.name', 'tenant_admin')
                ->get(['users.id', 'users.name', 'users.email', 'users.last_login_at', 'users.is_active']);

            return response()->json(['data' => array_merge($model->toArray(), [
                'branches' => $model->branches()->withCount('employees')->get(['id', 'name', 'city']),
                'admins' => $admins,
                'last_activity_at' => DB::table('users')->where('company_id', $model->id)->max('last_login_at'),
            ])]);
        });
    }

    public function update(Request $request, int $company): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($request, $company) {
            $model = Company::findOrFail($company);

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:191'],
                'legal_name' => ['nullable', 'string', 'max:191'],
                'industry' => ['sometimes', Rule::in(array_keys(Company::INDUSTRIES))],
                'email' => ['nullable', 'email', 'max:191'],
                'phone' => ['nullable', 'string', 'max:30'],
            ]);

            $model->update($validated);

            return response()->json(['data' => $model->fresh(), 'message' => 'Organisation updated.']);
        });
    }

    /**
     * Suspend / reactivate / cancel. A suspended tenant's users are refused
     * at login and on every request (ResolveTenant); their data is untouched.
     */
    public function setStatus(Request $request, int $company): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([Company::STATUS_ACTIVE, Company::STATUS_SUSPENDED, Company::STATUS_CANCELLED])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->context->withoutScoping(function () use ($validated, $company) {
            $model = Company::findOrFail($company);

            activity('platform')->performedOn($model)->event('status_changed')
                ->withProperties(['old' => ['status' => $model->status], 'attributes' => ['status' => $validated['status'], 'reason' => $validated['reason'] ?? null]])
                ->log('Organisation status changed');

            $model->forceFill([
                'status' => $validated['status'],
                'suspended_at' => $validated['status'] === Company::STATUS_ACTIVE ? null : now(),
                'suspension_reason' => $validated['status'] === Company::STATUS_ACTIVE ? null : ($validated['reason'] ?? null),
            ])->save();

            // End every live session for a tenant that is no longer active.
            if ($validated['status'] !== Company::STATUS_ACTIVE) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', \App\Models\User::class)
                    ->whereIn('tokenable_id', DB::table('users')->where('company_id', $model->id)->select('id'))
                    ->delete();
            }

            return response()->json(['data' => $model->fresh(), 'message' => 'Organisation status updated.']);
        });
    }
}
