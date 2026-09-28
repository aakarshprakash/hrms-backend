<?php

namespace App\Support\Tenancy;

use App\Models\Company;

/**
 * Holds the tenant (Company) the current request/job is acting for.
 *
 * Three states matter:
 *  - a company id is set: every BelongsToCompany model is filtered to it and
 *    new rows are stamped with it;
 *  - no company, enforced: an authenticated HTTP request that has no tenant
 *    (a platform admin outside support mode) -- tenant queries return
 *    nothing rather than everything (fail closed);
 *  - no company, not enforced: console commands, queued jobs, migrations and
 *    seeders, which deliberately operate across tenants and set the context
 *    explicitly (runAs) when they act on one.
 */
class TenantContext
{
    private ?int $companyId = null;

    private ?Company $company = null;

    private bool $enforced = false;

    private int $bypassDepth = 0;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
        $this->company = null;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function company(): ?Company
    {
        if ($this->companyId === null) {
            return null;
        }

        return $this->company ??= $this->withoutScoping(
            fn () => Company::find($this->companyId)
        );
    }

    public function enforce(bool $enforced = true): void
    {
        $this->enforced = $enforced;
    }

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    public function hasTenant(): bool
    {
        return $this->companyId !== null;
    }

    /**
     * Run $callback as $companyId, restoring the previous context afterwards
     * (even on exceptions). Used by console commands and jobs that iterate
     * over tenants.
     */
    public function runAs(?int $companyId, callable $callback): mixed
    {
        [$prevId, $prevCompany, $prevEnforced] = [$this->companyId, $this->company, $this->enforced];

        $this->set($companyId);
        $this->enforced = $companyId !== null;

        try {
            return $callback();
        } finally {
            $this->companyId = $prevId;
            $this->company = $prevCompany;
            $this->enforced = $prevEnforced;
        }
    }

    /**
     * Temporarily disable tenant filtering entirely. Reserved for platform
     * (super admin) features that must see across tenants -- never for
     * tenant-facing endpoints.
     */
    public function withoutScoping(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function reset(): void
    {
        $this->companyId = null;
        $this->company = null;
        $this->enforced = false;
        $this->bypassDepth = 0;
    }
}
