<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Company is a tenant: the SaaS customer account that owns branches,
 * employees and everything under them.
 */
class Company extends Model
{
    use Audited, HasFactory;

    protected string $auditLog = 'organisation';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const INDUSTRIES = [
        'automotive' => 'Automotive (showrooms & service)',
        'healthcare' => 'Healthcare (hospitals & clinics)',
        'finance' => 'Finance (banking, NBFC, insurance)',
        'retail' => 'Retail & distribution',
        'manufacturing' => 'Manufacturing',
        'hospitality' => 'Hospitality & restaurants',
        'it_services' => 'IT & professional services',
        'general' => 'General business',
    ];

    protected $fillable = [
        'name', 'slug', 'legal_name', 'industry', 'logo_path', 'timezone',
        'email', 'phone', 'website', 'address_line1', 'address_line2', 'city', 'state',
        'postal_code', 'country', 'currency_code', 'fiscal_year_start_month',
        'statutory', 'settings',
    ];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'statutory' => 'array',
            'settings' => 'array',
            'is_demo' => 'boolean',
            'suspended_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A tenant user can only ever resolve their own company row.
        static::addGlobalScope('own_company', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->hasTenant() && ! $context->isBypassed()) {
                $builder->where($builder->getModel()->qualifyColumn('id'), $context->id());
            }
        });
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return str_starts_with($this->logo_path, 'http')
            ? $this->logo_path
            : url('storage/' . ltrim($this->logo_path, '/'));
    }
}
