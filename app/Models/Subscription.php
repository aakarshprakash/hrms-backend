<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's subscription. The latest row is the current one.
 *
 *   trialing  → active (plan chosen / first invoice paid) → past_due (invoice overdue)
 *   trialing  → expired (trial over, no plan chosen)
 *   any       → cancelled
 */
class Subscription extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'billing';

    public const LIVE = ['trialing', 'active', 'past_due'];

    protected array $tenantParents = [];

    protected $fillable = [
        'plan_id',
        'status',
        'billing_cycle',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'plan_confirmed_at',
        'cancel_at_period_end',
        'cancelled_at',
        'custom_monthly_price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'date:Y-m-d',
            'current_period_end' => 'date:Y-m-d',
            'plan_confirmed_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'custom_monthly_price' => 'decimal:2',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->status !== 'trialing' || ! $this->trial_ends_at) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInHours($this->trial_ends_at, false) / 24));
    }

    public function monthlyPrice(int $activeEmployees): float
    {
        return $this->custom_monthly_price !== null
            ? (float) $this->custom_monthly_price
            : (float) $this->plan?->monthlyPriceFor($activeEmployees);
    }
}
