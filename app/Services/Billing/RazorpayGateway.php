<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Online payment of invoices through Razorpay Payment Links (UPI, cards,
 * net banking). Optional: without keys, invoices are settled offline and
 * marked paid by the platform admin.
 */
class RazorpayGateway
{
    public const API = 'https://api.razorpay.com/v1';

    public function enabled(): bool
    {
        return (bool) (config('billing.razorpay.key_id') && config('billing.razorpay.key_secret'));
    }

    /** A hosted payment page for the invoice (reused while it's still open). */
    public function paymentLink(Invoice $invoice): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Online payment is not set up.');
        }
        if ($invoice->gateway_link_url && $invoice->status === 'issued') {
            return $invoice->gateway_link_url;
        }

        $response = Http::timeout(20)
            ->withBasicAuth(config('billing.razorpay.key_id'), config('billing.razorpay.key_secret'))
            ->post(self::API . '/payment_links', [
                'amount' => (int) round((float) $invoice->total * 100),
                'currency' => 'INR',
                'accept_partial' => false,
                'reference_id' => $invoice->number,
                'description' => "Invoice {$invoice->number}",
                'customer' => array_filter([
                    'name' => $invoice->buyer['name'] ?? null,
                    'email' => $invoice->buyer['email'] ?? null,
                ]),
                'notify' => ['email' => true],
                'reminder_enable' => true,
                'callback_url' => rtrim((string) config('app.frontend_url'), '/') . '/settings/billing?paid=' . urlencode($invoice->number),
                'callback_method' => 'get',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Razorpay: ' . ($response->json('error.description') ?? $response->body()));
        }

        $invoice->forceFill(['gateway_link_id' => $response->json('id'), 'gateway_link_url' => $response->json('short_url')])->save();

        return $response->json('short_url');
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        $secret = config('billing.razorpay.webhook_secret');

        return $secret && $signature && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
