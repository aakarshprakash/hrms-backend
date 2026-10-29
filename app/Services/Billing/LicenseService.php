<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\LicenseKey;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\Cycles;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Licence keys for customers who pay offline. The platform records the
 * payment and issues a key for a plan and a number of months; redeeming it
 * activates the plan (or extends a running licence) and books a paid
 * invoice so collections and revenue include it. A licensed subscription
 * isn't invoiced at renewal: when its months run out it needs a new key.
 */
class LicenseService
{
    // Crockford-style alphabet: no 0/O, 1/I/L confusion when read over the phone.
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private TenantContext $context,
        private SubscriptionService $subscriptions,
        private BillingFeatureResolver $resolver,
    ) {
    }

    /** @return Collection<int, LicenseKey> */
    public function generate(array $data, User $by, int $count = 1): Collection
    {
        return DB::transaction(fn () => collect(range(1, max(1, $count)))->map(fn () => LicenseKey::create([
            'key' => $this->newKey(),
            'plan_id' => $data['plan_id'],
            'billing_cycle' => $data['billing_cycle'] ?? 'yearly',
            'months' => (int) $data['months'],
            'company_id' => $data['company_id'] ?? null,
            'amount' => $data['amount'] ?? 0,
            'payment_method' => $data['payment_method'] ?? null,
            'payment_reference' => $data['payment_reference'] ?? null,
            'paid_on' => $data['paid_on'] ?? null,
            'valid_until' => $data['valid_until'] ?? now()->addDays(90)->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $by->id,
        ])));
    }

    public function revoke(LicenseKey $key): LicenseKey
    {
        abort_unless($key->status === 'available', 422, 'Only an unused key can be revoked.');
        $key->update(['status' => 'revoked', 'revoked_at' => now()]);

        return $key;
    }

    /** Redeems a key for the company; returns its subscription. */
    public function redeem(Company $company, string $input, User $by): Subscription
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));

        return DB::transaction(function () use ($company, $code, $by) {
            $key = LicenseKey::with('plan')->whereRaw("REPLACE(`key`, '-', '') = ?", [$code])->lockForUpdate()->first();

            abort_unless($key, 422, 'That licence key isn’t valid. Check it and try again.');
            abort_if($key->status === 'redeemed', 422, 'This licence key has already been used.');
            abort_if($key->status === 'revoked', 422, 'This licence key has been cancelled. Contact support.');
            abort_if($key->isExpired(), 422, 'This licence key expired on ' . $key->valid_until->format('j M Y') . '. Contact support for a new one.');
            abort_if($key->company_id && $key->company_id !== $company->id, 422, 'This licence key was issued to another organisation.');
            abort_unless($key->plan && $key->plan->is_active, 422, 'The plan on this key is no longer available. Contact support.');

            $this->subscriptions->assertFits($company, $key->plan);

            $subscription = $this->context->runAs($company->id, function () use ($company, $key) {
                $current = $this->subscriptions->current($company);
                $today = CarbonImmutable::now()->startOfDay();

                // A licence already running on this plan: add the months on.
                if ($current && $current->billing_mode === 'license' && in_array($current->status, ['active', 'past_due'], true)
                    && $current->plan_id === $key->plan_id && $current->current_period_end && $current->current_period_end->gte($today)) {
                    $current->update([
                        'status' => 'active',
                        'license_key_id' => $key->id,
                        'current_period_end' => CarbonImmutable::parse($current->current_period_end)->addMonthsNoOverflow($key->months)->toDateString(),
                        'cancel_at_period_end' => false,
                    ]);

                    return $current->fresh('plan');
                }

                // Otherwise the licence starts today; whatever ran before (a trial, an online plan) ends.
                if ($current && $current->isLive()) {
                    $current->update(['status' => $current->status === 'trialing' ? 'expired' : 'cancelled', 'cancelled_at' => now(),
                        'notes' => trim(($current->notes ? $current->notes . ' ' : '') . "Replaced by licence {$key->masked()}.")]);
                }

                $subscription = new Subscription([
                    'plan_id' => $key->plan_id,
                    'status' => 'active',
                    'billing_cycle' => $key->billing_cycle,
                    'plan_confirmed_at' => now(),
                    'current_period_start' => $today->toDateString(),
                    'current_period_end' => $today->addMonthsNoOverflow($key->months)->subDay()->toDateString(),
                ]);
                $subscription->forceFill(['company_id' => $company->id, 'billing_mode' => 'license', 'license_key_id' => $key->id])->save();

                return $subscription->load('plan');
            });

            $invoice = (float) $key->amount > 0 ? $this->bookInvoice($company, $subscription, $key) : null;

            $key->update([
                'status' => 'redeemed', 'redeemed_company_id' => $company->id, 'redeemed_by' => $by->id,
                'redeemed_at' => now(), 'subscription_id' => $subscription->id, 'invoice_id' => $invoice?->id,
            ]);
            $this->resolver->forget($company->id);

            activity('billing')->causedBy($by)->event('license_redeemed')
                ->withProperties(['key' => $key->masked(), 'plan' => $key->plan->code, 'months' => $key->months])
                ->log("Licence redeemed: {$key->plan->name}, {$key->months} months");

            return $subscription;
        });
    }

    /** The amount collected (GST included) as a paid invoice for the licence period. */
    private function bookInvoice(Company $company, Subscription $subscription, LicenseKey $key): Invoice
    {
        $total = round((float) $key->amount, 2);
        $rate = (float) config('billing.gst_rate', 18);
        $subtotal = round($total / (1 + $rate / 100), 2);
        $tax = round($total - $subtotal, 2);
        $intraState = $company->state && strcasecmp(trim($company->state), trim((string) config('billing.seller.state'))) === 0;
        $start = CarbonImmutable::now()->startOfDay();
        $end = $start->addMonthsNoOverflow($key->months)->subDay();

        return $this->context->runAs($company->id, function () use ($company, $subscription, $key, $total, $subtotal, $tax, $intraState, $start, $end) {
            $invoice = new Invoice([
                'subscription_id' => $subscription->id,
                'number' => 'TMP-' . bin2hex(random_bytes(6)),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'lines' => [[
                    'description' => "{$key->plan->name} plan licence, {$key->months} month" . ($key->months === 1 ? '' : 's') . ' (' . Cycles::label($key->billing_cycle) . ") — key {$key->masked()}",
                    'quantity' => 1, 'unit_price' => $subtotal, 'amount' => $subtotal,
                ]],
                'subtotal' => $subtotal,
                'cgst' => $intraState ? round($tax / 2, 2) : 0,
                'sgst' => $intraState ? round($tax - round($tax / 2, 2), 2) : 0,
                'igst' => $intraState ? 0 : $tax,
                'total' => $total,
                'status' => 'paid',
                'issued_on' => now()->toDateString(),
                'due_on' => now()->toDateString(),
                'buyer' => [
                    'name' => $company->legal_name ?: $company->name,
                    'gstin' => $company->gstin,
                    'address' => trim(implode(', ', array_filter([$company->address_line1, $company->address_line2, $company->city, $company->postal_code]))),
                    'state' => $company->state,
                    'email' => $company->email,
                ],
            ]);
            $invoice->forceFill([
                'company_id' => $company->id,
                'paid_at' => $key->paid_on ? CarbonImmutable::parse($key->paid_on) : now(),
                'payment_method' => $key->payment_method ?: 'bank_transfer',
                'payment_reference' => $key->payment_reference,
            ])->save();
            $invoice->forceFill(['number' => sprintf('%s-%s-%06d', config('billing.invoice_prefix', 'PNX'), now()->year, $invoice->id)])->save();

            return $invoice;
        });
    }

    private function newKey(): string
    {
        do {
            $groups = [];
            for ($g = 0; $g < 4; $g++) {
                $groups[] = implode('', array_map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)], range(1, 5)));
            }
            $key = config('billing.invoice_prefix', 'PNX') . '-' . implode('-', $groups);
        } while (LicenseKey::where('key', $key)->exists());

        return $key;
    }
}
