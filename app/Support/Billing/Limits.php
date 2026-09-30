<?php

namespace App\Support\Billing;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan quotas (branches, active employees). Counts are taken through the
 * tenant scope, so they are always the acting company's own.
 */
final class Limits
{
    public const RESOURCES = ['branches', 'employees'];

    public static function limitFor(Company $company, string $resource): ?int
    {
        $resolver = app()->bound('billing.features') ? app('billing.features') : null;

        return $resolver?->limitFor($company, $resource);
    }

    public static function usage(string $resource): int
    {
        return match ($resource) {
            'branches' => Branch::count(),
            'employees' => Employee::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('status', 'active')->count(),
            default => 0,
        };
    }

    /** Throws 402 when adding one more $resource would exceed the plan. */
    public static function assertCanAdd(Company $company, string $resource, int $adding = 1): void
    {
        $limit = self::limitFor($company, $resource);

        if ($limit === null) {
            return;
        }

        if (self::usage($resource) + $adding > $limit) {
            throw new HttpException(402, "Your plan allows up to {$limit} {$resource}. Upgrade to add more.");
        }
    }
}
