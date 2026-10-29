<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A gateway payment attempt for a subscription invoice (a Razorpay order,
 * then the payment made against it). Platform-level data: tenants see the
 * invoice it settles, the platform console sees every attempt.
 */
class Payment extends Model
{
    protected $fillable = [
        'company_id',
        'invoice_id',
        'gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'amount',
        'currency',
        'status',
        'method',
        'error',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
