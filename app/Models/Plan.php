<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan: price, the modules it includes (App\Support\Billing\Features)
 * and its quotas. Platform-level data, shared by every tenant -- managed only
 * from the platform console.
 */
class Plan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'base_price',
        'per_employee_price',
        'included_employees',
        'features',
        'limits',
        'is_public',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'per_employee_price' => 'decimal:2',
            'included_employees' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Monthly price before GST: the base covers the first N people, then per head. */
    public function monthlyPriceFor(int $activeEmployees): float
    {
        $extra = max(0, $activeEmployees - (int) $this->included_employees);

        return round((float) $this->base_price + $extra * (float) $this->per_employee_price, 2);
    }

    public function limit(string $resource): ?int
    {
        $value = $this->limits[$resource] ?? null;

        return $value === null ? null : (int) $value;
    }
}
