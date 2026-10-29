<?php

namespace App\Services\Attendance;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\GeoSite;
use App\Support\Billing\Features;
use Illuminate\Support\Collection;

/**
 * Where web and mobile punches may come from.
 *
 *   branch     attendance_settings.geofence = {enabled, mode: block|flag, require_location}
 *              and any number of sites (geo_sites: name, point, radius);
 *   employee   geofence_mode: branch   -- any active site of their branch (default)
 *                             anywhere -- not restricted (field staff); location still recorded
 *                             sites    -- only their assigned sites (any branch)
 *
 * A punch is inside when it is within the radius of any allowed site. "block"
 * refuses a punch from outside; "flag" records it for HR (Location
 * exceptions report). Biometric devices and kiosks are never checked. The
 * whole module follows the plan ("geofence").
 */
class GeoFence
{
    public const DEFAULT_RADIUS = 200;

    public const MODES = ['branch' => 'On — branch sites', 'anywhere' => 'Off — can punch anywhere', 'sites' => 'On — specific sites'];

    /** @var array<int, Collection<int, GeoSite>> */
    private array $branchSites = [];

    /** @var array<int, Collection<int, GeoSite>> */
    private array $employeeSites = [];

    /** Organisation-wide master settings (companies.settings.geofence). */
    public function master(?Company $company): array
    {
        $m = (array) data_get($company?->settings ?? [], 'geofence', []);

        return [
            'enabled' => (bool) ($m['enabled'] ?? false),
            'mode' => ($m['mode'] ?? 'block') === 'flag' ? 'flag' : 'block',
            'require_location' => (bool) ($m['require_location'] ?? true),
            'default_radius' => (int) ($m['default_radius'] ?? self::DEFAULT_RADIUS),
        ];
    }

    /**
     * What applies in a branch: the master switch, unless the branch is
     * excluded; the master's block/flag unless the branch overrides it; and
     * only once the branch has a site. A plan module throughout.
     */
    public function settings(Branch $branch): array
    {
        $company = $branch->company;
        $master = $this->master($company);
        $g = (array) $branch->attendanceSetting('geofence', []);
        $inPlan = ! $company || Features::has($company, 'geofence');
        $sites = $this->sitesOf($branch->id);
        $branchOn = (bool) ($g['enabled'] ?? true);
        $override = in_array($g['mode'] ?? null, ['block', 'flag'], true) ? $g['mode'] : null;

        return [
            'enabled' => $inPlan && $master['enabled'] && $branchOn && $sites->isNotEmpty(),
            'branch_enabled' => $branchOn,
            'mode_override' => $override,
            'in_plan' => $inPlan,
            'mode' => $override ?? $master['mode'],
            'require_location' => $master['require_location'],
            'sites' => $sites->count(),
        ];
    }

    /** The sites an employee may punch from, or null when they aren't restricted. */
    public function allowedSites(Employee $employee): ?Collection
    {
        if ($employee->geofence_mode === 'anywhere') {
            return null;
        }
        if ($employee->geofence_mode === 'sites') {
            $assigned = $this->employeeSites[$employee->id] ??= $employee->geoSites()->where('is_active', true)->get();
            if ($assigned->isNotEmpty()) {
                return $assigned;
            }
        }

        return $this->sitesOf($employee->branch_id);
    }

    /**
     * Where a point is relative to an employee's allowed sites.
     *
     * @return array{enabled: bool, restricted: bool, inside: bool, distance_m: ?int, site: ?string, site_id: ?int}
     */
    public function evaluateFor(Employee $employee, ?float $lat, ?float $lng): array
    {
        $branch = $employee->branch;
        $enabled = $branch && $this->settings($branch)['enabled'];
        $sites = $enabled ? $this->allowedSites($employee) : null;
        $base = ['enabled' => $enabled, 'restricted' => $sites !== null, 'inside' => true, 'distance_m' => null, 'site' => null, 'site_id' => null];

        if ($lat === null || $lng === null) {
            return $base;
        }

        // Nearest of the allowed sites (or of the branch's, for the record, when unrestricted).
        $candidates = $sites ?? ($branch ? $this->sitesOf($branch->id) : collect());
        $nearest = null;
        foreach ($candidates as $site) {
            $d = (int) round($this->distance($site->latitude, $site->longitude, $lat, $lng));
            if (! $nearest || $d - $site->radius_m < $nearest['margin']) {
                $nearest = ['site' => $site, 'distance' => $d, 'margin' => $d - $site->radius_m];
            }
        }

        return array_merge($base, [
            'inside' => $sites === null || ($nearest && $nearest['margin'] <= 0),
            'distance_m' => $nearest['distance'] ?? null,
            'site' => $nearest['site']->name ?? null,
            'site_id' => $nearest['site']->id ?? null,
        ]);
    }

    /**
     * Decide a self-service punch. Returns [error message or null, payload to keep on the punch].
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public function checkPunch(Employee $employee, ?float $lat, ?float $lng): array
    {
        $branch = $employee->branch;
        if (! $branch || ! ($s = $this->settings($branch))['enabled']) {
            return [null, []];
        }

        $result = $this->evaluateFor($employee, $lat, $lng);
        if (! $result['restricted']) {
            return [null, array_filter(['geofence_exempt' => true, 'site' => $result['site'], 'distance_m' => $result['distance_m']], fn ($v) => $v !== null)];
        }

        if ($lat === null || $lng === null) {
            return $s['require_location']
                ? ['Turn on location to punch — you can only punch in from your work location.', []]
                : [null, ['location_missing' => true]];
        }

        if ($result['inside']) {
            return [null, ['site' => $result['site'], 'site_id' => $result['site_id'], 'distance_m' => $result['distance_m']]];
        }

        if ($s['mode'] === 'block') {
            return [sprintf('You are %s from %s. Punches are only allowed at your work location.', $this->human((int) $result['distance_m']), $result['site'] ?? $branch->name), []];
        }

        return [null, ['outside_geofence' => true, 'site' => $result['site'], 'distance_m' => $result['distance_m']]];
    }

    /** Great-circle distance in metres. */
    public function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** @return Collection<int, GeoSite> */
    private function sitesOf(?int $branchId): Collection
    {
        if (! $branchId) {
            return collect();
        }

        return $this->branchSites[$branchId] ??= GeoSite::where('branch_id', $branchId)->where('is_active', true)->orderBy('id')->get();
    }

    /** Called after sites change within the same request. */
    public function forget(): void
    {
        $this->branchSites = [];
        $this->employeeSites = [];
    }

    private function human(int $metres): string
    {
        return $metres >= 1000 ? round($metres / 1000, 1) . ' km' : $metres . ' m';
    }
}
