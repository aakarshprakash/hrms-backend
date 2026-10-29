<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Paying a subscription invoice online with Razorpay Checkout:
 *
 *   checkout(invoice)  → a Razorpay order + a payments row ("created");
 *   confirm(...)       → the signed result from Checkout: captured, invoice paid;
 *   captured(...)      → the same from the webhook (whichever arrives first wins);
 *   failed(...)        → the attempt is recorded as failed, the invoice stays open.
 *
 * Every step is idempotent: a payment is applied to its invoice once.
 */
class PaymentService
{
    public function __construct(
        private RazorpayGateway $razorpay,
        private InvoiceService $invoices,
        private BillingFeatureResolver $resolver,
        private TenantContext $context,
    ) {
    }

    /** @return array<string, mixed> what Razorpay Checkout needs to open */
    public function checkout(Invoice $invoice): array
    {
        abort_unless($invoice->status === 'issued', 422, 'This invoice is not open for payment.');
        abort_unless($this->razorpay->enabled(), 422, 'Online payment isn’t available — please pay by bank transfer.');

        $order = $this->razorpay->createOrder($invoice);

        Payment::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'gateway' => 'razorpay',
            'gateway_order_id' => $order['id'],
            'amount' => $invoice->total,
            'currency' => $order['currency'] ?? 'INR',
            'status' => 'created',
        ]);

        return [
            'key_id' => config('billing.razorpay.key_id'),
            'order_id' => $order['id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'] ?? 'INR',
            'name' => config('app.name'),
            'description' => "Invoice {$invoice->number}",
            'invoice_number' => $invoice->number,
            'prefill' => array_filter([
                'name' => $invoice->buyer['name'] ?? null,
                'email' => $invoice->buyer['email'] ?? null,
            ]),
        ];
    }

    /** The result Checkout hands back to the browser, verified by signature. */
    public function confirm(string $orderId, string $paymentId, string $signature): Payment
    {
        abort_unless($this->razorpay->validPaymentSignature($orderId, $paymentId, $signature), 422, 'We couldn’t verify this payment. If money was taken, it will be matched automatically.');

        return $this->captured($orderId, $paymentId, $this->razorpay->paymentMethod($paymentId));
    }

    public function captured(string $orderId, string $paymentId, ?string $method = null): Payment
    {
        return $this->context->withoutScoping(fn () => DB::transaction(function () use ($orderId, $paymentId, $method) {
            $payment = Payment::where('gateway_order_id', $orderId)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'captured') {
                $payment->update(['status' => 'captured', 'gateway_payment_id' => $paymentId, 'method' => $method ?? $payment->method,
                    'paid_at' => now(), 'error' => null]);

                $invoice = $payment->invoice_id ? Invoice::find($payment->invoice_id) : null;
                if ($invoice && $invoice->status === 'issued') {
                    $this->context->runAs($invoice->company_id, fn () => $this->invoices->markPaid($invoice, 'razorpay', $paymentId));
                }
                $this->resolver->forget($payment->company_id);
            }

            return $payment->fresh();
        }));
    }

    public function failed(string $orderId, ?string $paymentId, ?string $error): void
    {
        $this->context->withoutScoping(fn () => Payment::where('gateway_order_id', $orderId)->where('status', 'created')
            ->update(['status' => 'failed', 'gateway_payment_id' => $paymentId, 'error' => mb_substr((string) $error, 0, 500)]));
    }
}
