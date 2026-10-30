<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Support\Mobile\MobileAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The mobile app's switches: platform-wide on/off and versions here, which
 * plans include it (the plan editor's `mobile_app` module), and a
 * per-organisation override.
 */
class PlatformMobileController extends Controller
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => MobileAccess::settings(), 'plans' => $this->plans(), 'overrides' => MobileAccess::OVERRIDES]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'min_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,2}$/'],
            'latest_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,2}$/'],
            'android_url' => ['nullable', 'url', 'max:500'],
            'ios_url' => ['nullable', 'url', 'max:500'],
            'message' => ['nullable', 'string', 'max:255'],
            'plans' => ['sometimes', 'array'],
            'plans.*' => ['boolean'],
        ]);

        $settings = MobileAccess::save($v);

        // Which plans include the app — the same `mobile_app` module the plan editor shows.
        foreach ($v['plans'] ?? [] as $planId => $on) {
            $plan = Plan::find($planId);
            if (! $plan) {
                continue;
            }
            $features = array_values(array_diff((array) $plan->features, ['mobile_app']));
            if ($on) {
                $features[] = 'mobile_app';
            }
            $plan->update(['features' => $features]);
        }
        app('billing.features')->forgetAll();

        activity('platform')->causedBy($request->user())->event('mobile_settings')
            ->withProperties(['attributes' => $v])->log('Mobile app ' . ($settings['enabled'] ? 'settings saved' : 'switched off'));

        return response()->json(['data' => $settings, 'plans' => $this->plans(),
            'message' => $settings['enabled'] ? 'Mobile app settings saved.' : 'Mobile app switched off for everyone.']);
    }

    public function setCompany(Request $request, int $company): JsonResponse
    {
        $v = $request->validate(['mobile_app' => ['required', Rule::in(array_keys(MobileAccess::OVERRIDES))]]);

        return $this->context->withoutScoping(function () use ($v, $company, $request) {
            $model = Company::findOrFail($company);
            $model->forceFill(['settings' => array_merge($model->settings ?? [], ['mobile_app' => $v['mobile_app']])])->save();

            activity('platform')->causedBy($request->user())->performedOn($model)->event('mobile_app')
                ->withProperties(['attributes' => $v])->log('Mobile app access: ' . MobileAccess::OVERRIDES[$v['mobile_app']]);

            return response()->json(['data' => $this->companyState($model), 'message' => 'Mobile app access updated.']);
        });
    }

    public static function companyState(Company $company): array
    {
        return ['override' => MobileAccess::override($company), 'allowed' => MobileAccess::allowsCompany($company)];
    }

    private function plans(): array
    {
        return Plan::orderBy('sort_order')->get(['id', 'code', 'name', 'features'])
            ->map(fn (Plan $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'mobile_app' => in_array('mobile_app', (array) $p->features, true)])
            ->all();
    }
}
