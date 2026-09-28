<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Marks a model as tenant-owned (companies.id == tenant).
 *
 * - Queries are filtered to the current tenant (TenantScope).
 * - On insert, company_id is derived from the row's parent (its branch,
 *   employee, payroll run, ...) or else taken from the current context, so
 *   console commands and jobs that never set a context still stamp rows
 *   correctly.
 * - A write whose parent belongs to a different tenant than the row (or than
 *   the acting tenant) is refused outright. Validation should already have
 *   rejected the foreign id; this is the backstop.
 *
 * Models may declare `protected array $tenantParents = ['col' => 'table']`
 * to override which foreign keys identify the owning tenant.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new TenantScope());

        static::saving(function (Model $model) {
            $model->assignAndVerifyTenant();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string> foreign key column => parent table
     */
    public function tenantParentColumns(): array
    {
        return property_exists($this, 'tenantParents') ? $this->tenantParents : [
            'branch_id' => 'branches',
            'employee_id' => 'employees',
            'payroll_run_id' => 'payroll_runs',
        ];
    }

    protected function assignAndVerifyTenant(): void
    {
        $context = app(TenantContext::class);
        $derived = $this->deriveCompanyIdFromParents();

        if ($this->exists && $this->isDirty('company_id') && $this->getOriginal('company_id') !== null) {
            throw new TenantViolationException(sprintf(
                'Refusing to move %s #%s to another tenant.', static::class, $this->getKey()
            ));
        }

        if ($this->company_id === null) {
            $this->company_id = $derived ?? $context->id();
        }

        if ($this->company_id === null) {
            if ($this->allowsNullTenant()) {
                return;
            }

            throw new TenantViolationException(sprintf(
                'Cannot save %s without a tenant: no company context and no parent to derive it from.', static::class
            ));
        }

        if ($derived !== null && $derived !== (int) $this->company_id) {
            throw new TenantViolationException(sprintf(
                '%s references a parent that belongs to a different tenant.', static::class
            ));
        }

        if ($context->hasTenant() && ! $context->isBypassed() && (int) $this->company_id !== $context->id()) {
            throw new TenantViolationException(sprintf(
                'Refusing to write %s for a tenant other than the acting one.', static::class
            ));
        }
    }

    /**
     * Whether a null company_id is legitimate for this row (e.g. platform
     * admin users). Tenant data never is.
     */
    protected function allowsNullTenant(): bool
    {
        return false;
    }

    private function deriveCompanyIdFromParents(): ?int
    {
        foreach ($this->tenantParentColumns() as $column => $table) {
            $parentId = $this->getAttribute($column);

            if ($parentId === null || (! $this->isDirty($column) && $this->exists && $this->company_id !== null)) {
                continue;
            }

            $companyId = DB::table($table)->where('id', $parentId)->value('company_id');

            if ($companyId !== null) {
                return (int) $companyId;
            }
        }

        return null;
    }
}
