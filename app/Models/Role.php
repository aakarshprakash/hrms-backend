<?php

namespace App\Models;

use App\Support\Access\Roles;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Built-in roles are global (company_id NULL). Tenant-defined roles belong to
 * one company. Deliberately NOT globally scoped: Spatie builds its permission
 * cache by loading roles, and a tenant-filtered cache would be shared across
 * tenants. Use ->availableTo() when listing roles for a tenant.
 */
class Role extends SpatieRole
{
    protected $appends = ['label'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** Roles a tenant may see and assign: built-ins (minus platform) + its own. */
    public function scopeAvailableTo(Builder $query, ?int $companyId): Builder
    {
        return $query->where('name', '!=', Roles::SUPER_ADMIN)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            });
    }

    public function getLabelAttribute(): string
    {
        return $this->display_name
            ?: (Roles::SYSTEM[$this->name][0] ?? ucwords(str_replace('_', ' ', preg_replace('/^t\d+_/', '', $this->name))));
    }

    public function effectiveDataScope(): string
    {
        return Roles::scopeOf($this->name) ?? ($this->data_scope ?: Roles::SCOPE_BRANCH);
    }
}
