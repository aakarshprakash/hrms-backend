<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Scopes\BranchScope;
use App\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Subscription invoices: plan price for the period by active headcount,
 * GST split by place of supply (same state as the seller: CGST + SGST,
 * otherwise IGST), and a PDF.
 */
class InvoiceService
{
    public function activeEmployees(int $companyId): int
    {
        return app(TenantContext::class)->runAs($companyId, fn () => Employee::withoutGlobalScope(BranchScope::class)
            ->where('status', 'active')->count());
    }

    /**
     * What a period costs, before issuing anything (also the "next invoice"
     * estimate on the billing page).
     *
     * @return array{lines: list<array<string, mixed>>, subtotal: float, cgst: float, sgst: float, igst: float, total: float, employees: int}
     */
    public function quote(Subscription $subscription, Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $plan = $subscription->plan;
        $employees = $this->activeEmployees($company->id);
        $months = $subscription->billing_cycle === 'yearly' ? (int) config('billing.yearly_months_charged', 10) : 1;
        $period = $start->format('j M Y') . ' – ' . $end->format('j M Y');
        $lines = [];

        if ($subscription->custom_monthly_price !== null) {
            $lines[] = ['description' => "{$plan->name} plan (agreed price), {$period}", 'quantity' => $months, 'unit_price' => (float) $subscription->custom_monthly_price,
                'amount' => round($months * (float) $subscription->custom_monthly_price, 2)];
        } else {
            $lines[] = ['description' => "{$plan->name} plan, {$period} — includes {$plan->included_employees} employees", 'quantity' => $months,
                'unit_price' => (float) $plan->base_price, 'amount' => round($months * (float) $plan->base_price, 2)];
            $extra = max(0, $employees - (int) $plan->included_employees);
            if ($extra > 0 && (float) $plan->per_employee_price > 0) {
                $lines[] = ['description' => "Additional employees ({$extra} × {$months} month" . ($months > 1 ? 's' : '') . ')', 'quantity' => $extra * $months,
                    'unit_price' => (float) $plan->per_employee_price, 'amount' => round($extra * $months * (float) $plan->per_employee_price, 2)];
            }
        }

        if ($months > 1) {
            $lines[] = ['description' => 'Yearly billing: 12 months for the price of ' . $months, 'quantity' => 1, 'unit_price' => 0, 'amount' => 0];
        }

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $rate = (float) config('billing.gst_rate', 18);
        $intraState = $company->state && strcasecmp(trim($company->state), trim((string) config('billing.seller.state'))) === 0;
        $cgst = $sgst = $igst = 0.0;
        if ($intraState) {
            $cgst = $sgst = round($subtotal * $rate / 200, 2);
        } else {
            $igst = round($subtotal * $rate / 100, 2);
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'total' => round($subtotal + $cgst + $sgst + $igst, 2),
            'employees' => $employees,
        ];
    }

    public function issue(Subscription $subscription, CarbonImmutable $start, CarbonImmutable $end): ?Invoice
    {
        $company = app(TenantContext::class)->withoutScoping(fn () => Company::find($subscription->company_id));
        $q = $this->quote($subscription, $company, $start, $end);

        if ($q['total'] <= 0) {
            return null; // free / founding plans aren't invoiced
        }

        return app(TenantContext::class)->runAs($company->id, fn () => DB::transaction(function () use ($subscription, $company, $q, $start, $end) {
            $invoice = new Invoice([
                'subscription_id' => $subscription->id,
                'number' => 'TMP-' . bin2hex(random_bytes(6)),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'lines' => $q['lines'],
                'subtotal' => $q['subtotal'],
                'cgst' => $q['cgst'],
                'sgst' => $q['sgst'],
                'igst' => $q['igst'],
                'total' => $q['total'],
                'status' => 'issued',
                'issued_on' => now()->toDateString(),
                'due_on' => now()->addDays(7)->toDateString(),
                'buyer' => [
                    'name' => $company->legal_name ?: $company->name,
                    'gstin' => $company->gstin,
                    'address' => trim(implode(', ', array_filter([$company->address_line1, $company->address_line2, $company->city, $company->postal_code]))),
                    'state' => $company->state,
                    'email' => $company->email,
                ],
            ]);
            $invoice->forceFill(['company_id' => $company->id])->save();

            // Sequential, readable numbers: PNX-2026-000042.
            $invoice->forceFill(['number' => sprintf('%s-%s-%06d', config('billing.invoice_prefix', 'PNX'), now()->year, $invoice->id)])->save();

            return $invoice;
        }));
    }

    public function markPaid(Invoice $invoice, string $method, ?string $reference, ?string $paidAt = null): Invoice
    {
        abort_if($invoice->status === 'void', 422, 'A void invoice can’t be paid.');

        $invoice->forceFill([
            'status' => 'paid',
            'paid_at' => $paidAt ? CarbonImmutable::parse($paidAt) : now(),
            'payment_method' => $method,
            'payment_reference' => $reference,
        ])->save();

        $subscription = $invoice->subscription;
        if ($subscription && $subscription->status === 'past_due'
            && ! Invoice::where('subscription_id', $subscription->id)->where('status', 'issued')->where('due_on', '<', now()->subDays((int) config('billing.grace_days', 7))->toDateString())->exists()) {
            $subscription->update(['status' => 'active']);
        }

        return $invoice->fresh();
    }

    public function pdf(Invoice $invoice): string
    {
        $company = app(TenantContext::class)->withoutScoping(fn () => Company::find($invoice->company_id));

        $pdf = Pdf::loadView('invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'seller' => config('billing.seller'),
            'sac' => config('billing.sac_code'),
            'gstRate' => (float) config('billing.gst_rate', 18),
        ])->setPaper('a4');

        return $pdf->output();
    }
}
