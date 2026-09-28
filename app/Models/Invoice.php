<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A subscription invoice with GST, issued by the platform to a tenant. */
class Invoice extends Model
{
    use Audited, BelongsToCompany;

    protected string $auditLog = 'billing';

    protected array $tenantParents = ['subscription_id' => 'subscriptions'];

    protected $fillable = [
        'subscription_id',
        'number',
        'period_start',
        'period_end',
        'lines',
        'subtotal',
        'cgst',
        'sgst',
        'igst',
        'total',
        'status',
        'issued_on',
        'due_on',
        'paid_at',
        'payment_method',
        'payment_reference',
        'gateway_link_id',
        'gateway_link_url',
        'buyer',
    ];

    protected $hidden = ['gateway_link_id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'issued_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'lines' => 'array',
            'buyer' => 'array',
            'subtotal' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'issued' && $this->due_on && $this->due_on->lt(now()->startOfDay());
    }
}
