<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A platform-issued licence key (not tenant-scoped: the platform owns it until redeemed). */
class LicenseKey extends Model
{
    protected $fillable = [
        'key', 'plan_id', 'billing_cycle', 'months', 'company_id', 'status', 'amount', 'payment_method',
        'payment_reference', 'paid_on', 'valid_until', 'notes', 'created_by', 'redeemed_company_id',
        'redeemed_by', 'redeemed_at', 'subscription_id', 'invoice_id', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'redeemed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'months' => 'integer',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function redeemedCompany()
    {
        return $this->belongsTo(Company::class, 'redeemed_company_id');
    }

    /** PNX-7K2Q-…-…-… with the last group visible. */
    public function masked(): string
    {
        return preg_replace('/^(\w+)-.*-(\w+)$/', '$1-••••-••••-••••-$2', $this->key);
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }
}
