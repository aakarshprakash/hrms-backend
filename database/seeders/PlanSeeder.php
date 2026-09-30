<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Runs on every deploy (via ProductionSeeder), so it only ever adds:
 *  - plans from config/billing.php that don't exist yet (a plan the
 *    platform admin has edited is left exactly as it is);
 *  - a subscription for any organisation without one -- organisations that
 *    were already using the product join the "founding customer" plan, so
 *    nothing about how they work changes and they are never invoiced.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('billing.plans', []) as $code => $plan) {
            Plan::firstOrCreate(['code' => $code], [
                'name' => $plan['name'],
                'description' => $plan['description'] ?? null,
                'base_price' => $plan['base_price'] ?? 0,
                'per_employee_price' => $plan['per_employee_price'] ?? 0,
                'included_employees' => $plan['included_employees'] ?? 0,
                'features' => $plan['features'] ?? ['core'],
                'limits' => $plan['limits'] ?? ['branches' => null, 'employees' => null],
                'is_public' => $plan['is_public'] ?? true,
                'is_active' => true,
                'sort_order' => $plan['sort_order'] ?? 0,
            ]);
        }

        $service = app(SubscriptionService::class);
        $withSubscription = Subscription::query()->select('company_id');

        app(TenantContext::class)->withoutScoping(function () use ($service, $withSubscription) {
            foreach (Company::whereNotIn('id', $withSubscription)->get() as $company) {
                $service->assignLegacy($company);
                $this->command?->info("{$company->name}: founding customer plan.");
            }
        });
    }
}
