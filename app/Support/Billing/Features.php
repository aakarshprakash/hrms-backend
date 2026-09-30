<?php

namespace App\Support\Billing;

use App\Models\Company;

/**
 * Plan-gated modules. A feature key names a module a subscription plan can
 * include; the `feature:` route middleware and the SPA both read from here.
 */
final class Features
{
    public const ALL = [
        'core' => 'Employees, organisation, basic attendance and leave',
        'self_service' => 'Employee self-service portal',
        'biometric' => 'Biometric device integration',
        'shifts' => 'Shift rosters, rotations and swaps',
        'payroll' => 'Payroll engine and payslips',
        'statutory' => 'PF, ESI, PT, TDS and compliance reports',
        'certificates' => 'Letters and certificates',
        'notifications' => 'SMS and WhatsApp notifications',
        'insights' => 'AI insights and analytics',
        'audit_log' => 'Audit trail',
        'multi_branch' => 'More than one branch',
    ];

    /** @return list<string> */
    public static function enabledFor(Company $company): array
    {
        $resolver = self::resolver();

        return $resolver ? $resolver->enabledFor($company) : array_keys(self::ALL);
    }

    public static function has(Company $company, string $feature): bool
    {
        return in_array($feature, self::enabledFor($company), true);
    }

    public static function subscriptionSummary(Company $company): ?array
    {
        return self::resolver()?->summary($company);
    }

    /** Set by the billing module once installed; until then every feature is on. */
    private static function resolver(): ?object
    {
        return app()->bound('billing.features') ? app('billing.features') : null;
    }
}
