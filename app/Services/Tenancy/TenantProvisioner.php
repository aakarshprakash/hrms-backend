<?php

namespace App\Services\Tenancy;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Access\Roles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a ready-to-use tenant in one transaction: the company, its first
 * branch (with the industry starter template applied), the tenant admin
 * login and -- once billing is installed -- a trial subscription.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly IndustryTemplateService $templates,
    ) {
    }

    /**
     * @param  array{
     *   company_name: string, industry?: string, legal_name?: string, email?: string, phone?: string,
     *   state?: string, city?: string, timezone?: string, currency_code?: string,
     *   branch_name?: string, apply_template?: bool, is_demo?: bool, plan_code?: string,
     *   admin_name: string, admin_email: string, admin_password: string, admin_phone?: string,
     * }  $data
     * @return array{company: Company, branch: Branch, admin: User}
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $company = $this->context->withoutScoping(function () use ($data) {
                $company = new Company([
                    'name' => $data['company_name'],
                    'slug' => $data['slug'] ?? $this->uniqueSlug($data['company_name']),
                    'legal_name' => $data['legal_name'] ?? null,
                    'industry' => $data['industry'] ?? 'general',
                    'email' => $data['email'] ?? $data['admin_email'],
                    'phone' => $data['phone'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
                    'currency_code' => $data['currency_code'] ?? 'INR',
                ]);
                $company->forceFill([
                    'is_demo' => (bool) ($data['is_demo'] ?? false),
                    'status' => Company::STATUS_ACTIVE,
                ])->save();

                return $company;
            });

            return $this->context->runAs($company->id, function () use ($company, $data) {
                $branch = Branch::create([
                    'company_id' => $company->id,
                    'name' => $data['branch_name'] ?? 'Head Office',
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'country' => 'India',
                    'timezone' => $company->timezone,
                    'currency_code' => $company->currency_code,
                    'payroll_days_in_month' => 30,
                ]);

                if ($data['apply_template'] ?? true) {
                    $this->templates->apply($branch, $company->industry);
                }

                $admin = User::create([
                    'name' => $data['admin_name'],
                    'email' => strtolower($data['admin_email']),
                    'phone' => $data['admin_phone'] ?? null,
                    'password' => Hash::make($data['admin_password']),
                    'user_type' => 'system',
                ]);
                $admin->forceFill(['password_changed_at' => now()])->save();
                $admin->assignRole(Roles::TENANT_ADMIN);

                // A trial on the default plan (or the one chosen at sign-up); demo
                // organisations get every module for free. Skipped until plans exist.
                $billing = app(\App\Services\Billing\SubscriptionService::class);
                if (! empty($data['is_demo'])) {
                    $billing->startDemo($company);
                } elseif (\App\Models\Plan::where('code', $data['plan_code'] ?? config('billing.trial_plan'))->exists()) {
                    $billing->startTrial($company, $data['plan_code'] ?? null);
                }

                return ['company' => $company->fresh(), 'branch' => $branch, 'admin' => $admin];
            });
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'company', 60, '');
        $slug = $base;

        // Called inside withoutScoping(): sees every tenant's slugs.
        for ($i = 2; Company::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
