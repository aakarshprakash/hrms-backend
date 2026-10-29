<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\GeoSite;
use App\Services\Attendance\GeoFence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Geo-fencing setup: per-branch switch and behaviour, the branch's sites,
 * and per-employee rules (branch premises / anywhere / specific sites).
 */
class GeofenceController extends Controller
{
    public function __construct(private GeoFence $geoFence)
    {
    }

    /** Every branch the user can manage, with its settings, sites and how many people follow each rule. */
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::orderBy('name')->get()->filter(fn ($b) => $request->user()->canAccessBranch($b->id))->values();
        $sites = GeoSite::whereIn('branch_id', $branches->pluck('id'))->withCount('employees')->orderBy('id')->get()->groupBy('branch_id');
        $modes = Employee::where('status', 'active')->whereIn('branch_id', $branches->pluck('id'))
            ->select('branch_id', 'geofence_mode', DB::raw('COUNT(*) as total'))->groupBy('branch_id', 'geofence_mode')->get()
            ->groupBy('branch_id');

        $company = app(TenantContext::class)->company();

        return response()->json(['master' => $this->geoFence->master($company) + [
            'in_plan' => ! $company || \App\Support\Billing\Features::has($company, 'geofence'),
            'employees' => Employee::where('status', 'active')->select('geofence_mode', DB::raw('COUNT(*) as total'))->groupBy('geofence_mode')->pluck('total', 'geofence_mode'),
        ], 'data' => $branches->map(fn (Branch $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'city' => $b->city,
            'address' => $b->address,
            'settings' => $this->geoFence->settings($b),
            'sites' => ($sites[$b->id] ?? collect())->map(fn (GeoSite $s) => $s->only(['id', 'name', 'latitude', 'longitude', 'radius_m', 'address', 'is_active']) + ['assigned' => $s->employees_count])->values(),
            'employees' => collect(GeoFence::MODES)->mapWithKeys(fn ($l, $m) => [$m => (int) (($modes[$b->id] ?? collect())->firstWhere('geofence_mode', $m)->total ?? 0)]),
        ]), 'modes' => GeoFence::MODES]);
    }

    /** The organisation-wide master switch and defaults. */
    public function master(Request $request): JsonResponse
    {
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'mode' => ['required', Rule::in(['block', 'flag'])],
            'require_location' => ['required', 'boolean'],
            'default_radius' => ['required', 'integer', 'min:25', 'max:50000'],
        ]);
        abort_if($v['enabled'] && ! GeoSite::where('is_active', true)->exists(), 422, 'Add at least one site to a branch before turning geo-fencing on.');

        $company = app(TenantContext::class)->company();
        $settings = $company->settings ?? [];
        $settings['geofence'] = $v;
        $company->forceFill(['settings' => $settings])->save();

        activity('organisation')->causedBy($request->user())->event('geofence_master')->withProperties($v)
            ->log('Geo-fencing ' . ($v['enabled'] ? 'turned on' : 'turned off') . ' for the organisation');

        return response()->json(['data' => $this->geoFence->master($company->fresh()), 'message' => $v['enabled']
            ? 'Geo-fencing is on for the organisation.' : 'Geo-fencing is off — nobody’s punch location is restricted.']);
    }

    /** A branch: included or excluded, and optionally its own block / flag behaviour. */
    public function settings(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($branch->id);
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'mode' => ['nullable', Rule::in(['block', 'flag'])],
        ]);

        $settings = $branch->attendance_settings ?? [];
        $settings['geofence'] = ['enabled' => $v['enabled'], 'mode' => $v['mode'] ?? null];
        $branch->update(['attendance_settings' => $settings]);

        return response()->json(['data' => $this->geoFence->settings($branch->fresh()), 'message' => $v['enabled']
            ? "{$branch->name} follows the organisation’s geo-fencing." : "{$branch->name} is excluded from geo-fencing."]);
    }

    public function storeSite(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($branch->id);
        $site = GeoSite::create(['branch_id' => $branch->id] + $this->validateSite($request));

        return response()->json(['data' => $site, 'message' => "Site “{$site->name}” added."], 201);
    }

    public function updateSite(Request $request, GeoSite $site): JsonResponse
    {
        $this->authorizeBranch($site->branch_id);
        $site->update($this->validateSite($request, true));

        return response()->json(['data' => $site->fresh(), 'message' => 'Site saved.']);
    }

    public function destroySite(GeoSite $site): JsonResponse
    {
        $this->authorizeBranch($site->branch_id);
        $last = ! GeoSite::where('branch_id', $site->branch_id)->where('is_active', true)->whereKeyNot($site->id)->exists();
        abort_if($last && $this->geoFence->settings($site->branch)['enabled'], 422,
            'This is the branch’s only site — exclude the branch from geo-fencing first, or add another site.');

        $site->delete();

        return response()->json(['message' => 'Site removed.']);
    }

    /** Employees with their punch-location rule. */
    public function employees(Request $request): JsonResponse
    {
        $branchId = $this->requestedBranchId();
        $page = Employee::with(['geoSites:id,name,branch_id', 'branch:id,name'])->visibleTo($request->user())->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('mode'), fn ($q) => $q->where('geofence_mode', $request->string('mode')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', '%' . $request->string('search') . '%')
                ->orWhere('last_name', 'like', '%' . $request->string('search') . '%')
                ->orWhere('employee_code', 'like', '%' . $request->string('search') . '%')))
            ->orderBy('first_name')
            ->paginate(min(max($request->integer('per_page', 50), 10), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $e) => [
                'id' => $e->id, 'employee_code' => $e->employee_code, 'name' => $e->full_name, 'branch' => $e->branch?->name,
                'mode' => $e->geofence_mode ?: 'branch', 'sites' => $e->geoSites->map->only(['id', 'name']),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Set the rule for one or many employees at once. */
    public function assign(Request $request): JsonResponse
    {
        $v = $request->validate([
            'employee_ids' => 'required|array|min:1|max:500',
            'employee_ids.*' => 'integer|exists:employees,id',
            'mode' => ['required', Rule::in(array_keys(GeoFence::MODES))],
            'site_ids' => 'required_if:mode,sites|array',
            'site_ids.*' => 'integer|exists:geo_sites,id',
        ]);
        $employees = Employee::visibleTo($request->user())->whereIn('id', $v['employee_ids'])->get();
        abort_if($employees->count() !== count(array_unique($v['employee_ids'])), 404, 'Employee not found.');
        $siteIds = $v['mode'] === 'sites' ? GeoSite::whereIn('id', $v['site_ids'] ?? [])->pluck('id')->all() : [];
        abort_if($v['mode'] === 'sites' && ! $siteIds, 422, 'Choose at least one site.');

        DB::transaction(function () use ($employees, $v, $siteIds) {
            foreach ($employees as $e) {
                $e->forceFill(['geofence_mode' => $v['mode']])->save();
                $e->geoSites()->sync($siteIds);
            }
        });

        return response()->json(['message' => $employees->count() . ' employee(s) set to “' . GeoFence::MODES[$v['mode']] . '”.']);
    }

    private function validateSite(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'latitude' => [$req, 'numeric', 'between:-90,90'],
            'longitude' => [$req, 'numeric', 'between:-180,180'],
            'radius_m' => [$req, 'integer', 'min:25', 'max:50000'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
