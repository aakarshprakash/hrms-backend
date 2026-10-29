<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\InvoiceService;
use App\Support\Billing\Cycles;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The platform operator's money view across every client organisation:
 * recurring revenue, collections, outstanding invoices, trials about to
 * convert, and the subscription / invoice / payment ledgers.
 */
class PlatformRevenueController extends Controller
{
    public function __construct(private TenantContext $context)
    {
    }

    public function overview(): JsonResponse
    {
        return $this->context->withoutScoping(function () {
            $subscriptions = $this->latestSubscriptions();
            $headcounts = $this->headcounts();
            $today = CarbonImmutable::now();

            $mrr = $subscriptions->whereIn('status', ['active', 'past_due'])
                ->sum(fn (Subscription $s) => $this->monthlyValue($s, $headcounts[$s->company_id] ?? 0));
            $pipeline = $subscriptions->where('status', 'trialing')->whereNotNull('plan_confirmed_at')
                ->sum(fn (Subscription $s) => $this->monthlyValue($s, $headcounts[$s->company_id] ?? 0));

            $paid = Invoice::where('status', 'paid')->where('paid_at', '>=', $today->subMonths(11)->startOfMonth())->get(['total', 'paid_at']);
            $series = collect(range(11, 0))->map(function ($ago) use ($today, $paid) {
                $month = $today->subMonths($ago);
                $key = $month->format('Y-m');

                return [
                    'month' => $key,
                    'label' => $month->format('M y'),
                    'collected' => round((float) $paid->filter(fn ($i) => $i->paid_at->format('Y-m') === $key)->sum('total'), 2),
                ];
            })->values();

            $open = Invoice::where('status', 'issued')->get(['total', 'due_on']);
            $graceCutoff = $today->subDays((int) config('billing.grace_days', 7))->startOfDay();

            $names = Company::pluck('name', 'id');

            return response()->json(['data' => [
                'mrr' => round($mrr, 2),
                'arr' => round($mrr * 12, 2),
                'trial_pipeline_mrr' => round($pipeline, 2),
                'collected_this_month' => $series->last()['collected'],
                'collected_last_month' => $series[count($series) - 2]['collected'],
                'outstanding' => round((float) $open->sum('total'), 2),
                'overdue' => round((float) $open->filter(fn ($i) => $i->due_on->lt($graceCutoff))->sum('total'), 2),
                'open_invoices' => $open->count(),
                'by_status' => $subscriptions->groupBy('status')->map->count(),
                'by_plan' => $subscriptions->whereIn('status', ['trialing', 'active', 'past_due'])
                    ->groupBy(fn ($s) => $s->plan?->name ?? '—')->map->count(),
                'series' => $series,
                'trials_ending' => $subscriptions->where('status', 'trialing')
                    ->filter(fn ($s) => $s->trial_ends_at && $s->trial_ends_at->lte($today->addDays(7)))
                    ->sortBy('trial_ends_at')->values()
                    ->map(fn (Subscription $s) => [
                        'company_id' => $s->company_id,
                        'company' => $names[$s->company_id] ?? '—',
                        'plan' => $s->plan?->name,
                        'billing_cycle' => $s->billing_cycle,
                        'trial_ends_at' => $s->trial_ends_at->toIso8601String(),
                        'plan_confirmed' => $s->plan_confirmed_at !== null,
                        'monthly_value' => round($this->monthlyValue($s, $headcounts[$s->company_id] ?? 0), 2),
                    ]),
                'recent_payments' => Payment::with('invoice:id,number')->where('status', 'captured')->latest('paid_at')->limit(8)->get()
                    ->map(fn (Payment $p) => $p->only(['id', 'company_id', 'amount', 'method', 'paid_at', 'gateway_payment_id']) + [
                        'company' => $names[$p->company_id] ?? '—',
                        'invoice_number' => $p->invoice?->number,
                    ]),
                'online_payment' => (bool) (config('billing.razorpay.key_id') && config('billing.razorpay.key_secret')),
            ]]);
        });
    }

    /** Every organisation's current subscription, with its value and what it owes. */
    public function subscriptions(Request $request): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($request) {
            $headcounts = $this->headcounts();
            $outstanding = Invoice::where('status', 'issued')->select('company_id', DB::raw('SUM(total) as total'))
                ->groupBy('company_id')->pluck('total', 'company_id');
            $companies = Company::get(['id', 'name', 'slug', 'email', 'status', 'created_at'])->keyBy('id');

            $rows = $this->latestSubscriptions()
                ->when($request->filled('status'), fn ($c) => $c->where('status', $request->string('status')->toString()))
                ->when($request->filled('plan'), fn ($c) => $c->filter(fn ($s) => $s->plan?->code === $request->string('plan')->toString()))
                ->filter(fn ($s) => $companies->has($s->company_id))
                ->filter(function ($s) use ($request, $companies) {
                    if (! $request->filled('search')) {
                        return true;
                    }
                    $term = mb_strtolower($request->string('search')->toString());
                    $c = $companies[$s->company_id];

                    return str_contains(mb_strtolower($c->name), $term) || str_contains(mb_strtolower((string) $c->email), $term);
                })
                ->map(fn (Subscription $s) => [
                    'id' => $s->id,
                    'company_id' => $s->company_id,
                    'company' => $companies[$s->company_id]->name,
                    'company_email' => $companies[$s->company_id]->email,
                    'company_status' => $companies[$s->company_id]->status,
                    'signed_up_at' => $companies[$s->company_id]->created_at?->toIso8601String(),
                    'plan' => $s->plan?->only(['code', 'name']),
                    'status' => $s->status,
                    'billing_cycle' => $s->billing_cycle,
                    'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
                    'plan_confirmed' => $s->plan_confirmed_at !== null,
                    'current_period_end' => $s->current_period_end?->toDateString(),
                    'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
                    'employees' => $headcounts[$s->company_id] ?? 0,
                    'monthly_value' => round($this->monthlyValue($s, $headcounts[$s->company_id] ?? 0), 2),
                    'outstanding' => round((float) ($outstanding[$s->company_id] ?? 0), 2),
                ])
                ->sortByDesc('monthly_value')->values();

            return response()->json(['data' => $rows, 'plans' => Plan::orderBy('sort_order')->get(['code', 'name'])]);
        });
    }

    public function invoices(Request $request): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($request) {
            $query = Invoice::query()->with('subscription.plan:id,name')
                ->join('companies', 'companies.id', '=', 'invoices.company_id')
                ->select('invoices.*', 'companies.name as company_name');

            match ($request->string('status')->toString()) {
                'open' => $query->where('invoices.status', 'issued'),
                'overdue' => $query->where('invoices.status', 'issued')->where('invoices.due_on', '<', now()->toDateString()),
                'paid' => $query->where('invoices.status', 'paid'),
                'void' => $query->where('invoices.status', 'void'),
                default => null,
            };
            if ($request->filled('company_id')) {
                $query->where('invoices.company_id', $request->integer('company_id'));
            }
            if ($request->filled('search')) {
                $term = '%' . $request->string('search') . '%';
                $query->where(fn ($q) => $q->where('invoices.number', 'like', $term)->orWhere('companies.name', 'like', $term));
            }

            $page = $query->orderByDesc('invoices.issued_on')->orderByDesc('invoices.id')->paginate(25);

            return response()->json([
                'data' => collect($page->items())->map(fn (Invoice $i) => $i->toArray() + ['overdue' => $i->isOverdue()]),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            ]);
        });
    }

    public function invoicePdf(int $invoice, InvoiceService $invoices)
    {
        $model = $this->context->withoutScoping(fn () => Invoice::findOrFail($invoice));
        $pdf = $this->context->runAs($model->company_id, fn () => $invoices->pdf($model));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$model->number}.pdf\"",
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($request) {
            $query = Payment::query()->with(['invoice:id,number', 'company:id,name']);
            if ($request->filled('status')) {
                $query->where('status', $request->string('status')->toString());
            }
            if ($request->filled('search')) {
                $term = '%' . $request->string('search') . '%';
                $query->where(fn ($q) => $q->where('gateway_payment_id', 'like', $term)->orWhere('gateway_order_id', 'like', $term)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $term))
                    ->orWhereHas('invoice', fn ($c) => $c->where('number', 'like', $term)));
            }

            $page = $query->latest('id')->paginate(25);

            return response()->json([
                'data' => $page->items(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
                'totals' => [
                    'captured' => round((float) Payment::where('status', 'captured')->sum('amount'), 2),
                    'failed' => Payment::where('status', 'failed')->count(),
                ],
            ]);
        });
    }

    /** @return Collection<int, Subscription> each company's latest subscription */
    private function latestSubscriptions(): Collection
    {
        $latestIds = Subscription::query()->selectRaw('MAX(id) as id')->groupBy('company_id')->pluck('id');

        return Subscription::with('plan')->whereIn('id', $latestIds)->get();
    }

    /** @return Collection<int, int> active employees per company */
    private function headcounts(): Collection
    {
        return DB::table('employees')->where('status', 'active')
            ->select('company_id', DB::raw('COUNT(*) as total'))->groupBy('company_id')->pluck('total', 'company_id');
    }

    /** Recurring value per month, after the cycle's discount, before GST. */
    private function monthlyValue(Subscription $s, int $employees): float
    {
        $cycle = $s->billing_cycle ?: 'monthly';

        return $s->monthlyPrice($employees) * Cycles::monthsCharged($cycle) / max(1, Cycles::months($cycle));
    }
}
