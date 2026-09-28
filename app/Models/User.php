<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\BranchScope;
use App\Support\Access\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Audited;

    protected string $auditLog = 'security';

    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'employee_id',
        'is_super_admin',
        'user_type',
        'branch_id',
        'is_active',
        'must_change_password',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected array $tenantParents = [
        'branch_id' => 'branches',
        'employee_id' => 'employees',
    ];

    /** Per-instance memo; the authenticated user is consulted on every scoped query. */
    private ?array $accessMemo = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The user's own employee id. Some accounts are linked only from the
     * employee side (employees.user_id); resolve that too so self-service
     * scoping never silently treats such a user as having no record.
     */
    protected function employeeId(): Attribute
    {
        return Attribute::get(function ($value) {
            if ($value !== null || ! $this->exists) {
                return $value;
            }

            return $this->memo('employee_id', fn () => Employee::withoutGlobalScope(BranchScope::class)
                ->where('user_id', $this->id)->value('id'));
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Additional branches beyond branch_id (e.g. an area manager). */
    public function extraBranches()
    {
        return $this->belongsToMany(Branch::class, 'branch_user')->withTimestamps();
    }

    protected function allowsNullTenant(): bool
    {
        // Only the platform operator exists outside every tenant.
        return (bool) $this->is_super_admin;
    }

    // ── Access model ───────────────────────────────────────────────────────

    /** The SaaS operator: sees every tenant (via support mode). */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_super_admin && $this->company_id === null;
    }

    /** Owns their company: every branch, every module. */
    public function isTenantAdmin(): bool
    {
        return $this->company_id !== null && $this->hasRole(Roles::TENANT_ADMIN);
    }

    /**
     * Whose records this user sees: company > branch > team > self. The
     * broadest scope across their roles wins; built-in roles use the scope
     * fixed in Roles::SYSTEM, custom roles their roles.data_scope.
     */
    public function dataScope(): string
    {
        return $this->memo('scope', function () {
            if ($this->isPlatformAdmin() || $this->isTenantAdmin()) {
                return Roles::SCOPE_COMPANY;
            }

            $scopes = $this->roles->map(
                fn ($role) => Roles::scopeOf($role->name) ?? ($role->data_scope ?: Roles::SCOPE_BRANCH)
            )->all();

            return empty($scopes) ? Roles::SCOPE_SELF : Roles::broadest($scopes);
        });
    }

    public function hasCompanyWideAccess(): bool
    {
        return $this->dataScope() === Roles::SCOPE_COMPANY;
    }

    /**
     * Branch ids this user is confined to, or null for "every branch of the
     * tenant". A user with no branch assignment at all keeps the pre-SaaS
     * behaviour of spanning every branch (e.g. head-office HR).
     */
    public function accessibleBranchIds(): ?array
    {
        return $this->memo('branches', function () {
            if ($this->hasCompanyWideAccess()) {
                return null;
            }

            $ids = DB::table('branch_user')->where('user_id', $this->id)->pluck('branch_id')->all();

            if ($this->branch_id) {
                $ids[] = $this->branch_id;
            }

            $ids = array_values(array_unique(array_map('intval', $ids)));

            return empty($ids) ? null : $ids;
        });
    }

    public function canAccessBranch(?int $branchId): bool
    {
        $ids = $this->accessibleBranchIds();

        return $ids === null || ($branchId !== null && in_array($branchId, $ids, true));
    }

    /** Employee ids in this user's reporting tree, including themselves. */
    public function teamEmployeeIds(): array
    {
        return $this->memo('team', function () {
            if (! $this->employee_id) {
                return [];
            }

            $ids = [$this->employee_id];
            $frontier = [$this->employee_id];

            // Bounded walk down the reporting tree (guards against cycles).
            for ($depth = 0; $depth < 10 && ! empty($frontier); $depth++) {
                $frontier = Employee::withoutGlobalScope(BranchScope::class)
                    ->whereIn('reporting_manager_id', $frontier)
                    ->whereNotIn('id', $ids)
                    ->pluck('id')
                    ->all();
                $ids = array_merge($ids, $frontier);
            }

            return $ids;
        });
    }

    /** Legacy accessor: [] meant "all branches". */
    public function getAccessibleBranchIdsAttribute(): array
    {
        return $this->accessibleBranchIds() ?? [];
    }

    public function flushAccessCache(): void
    {
        $this->accessMemo = null;
    }

    private function memo(string $key, callable $resolve): mixed
    {
        $this->accessMemo ??= [];

        if (! array_key_exists($key, $this->accessMemo)) {
            $this->accessMemo[$key] = $resolve();
        }

        return $this->accessMemo[$key];
    }
}
