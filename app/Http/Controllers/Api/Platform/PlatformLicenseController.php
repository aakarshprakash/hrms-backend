<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\LicenseKey;
use App\Models\PlatformSetting;
use App\Services\Billing\LicenseService;
use App\Support\Billing\Cycles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Licence keys for offline payments, and platform-wide billing switches. */
class PlatformLicenseController extends Controller
{
    public const PAYMENT_METHODS = ['bank_transfer', 'upi', 'cheque', 'cash', 'other'];

    public function __construct(private TenantContext $context, private LicenseService $licenses)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->context->withoutScoping(function () use ($request) {
            $keys = LicenseKey::with(['plan:id,code,name', 'company:id,name', 'redeemedCompany:id,name'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('company_id'), fn ($q) => $q->where(fn ($w) => $w->where('company_id', $request->integer('company_id'))
                    ->orWhere('redeemed_company_id', $request->integer('company_id'))))
                ->when($request->filled('search'), fn ($q) => $q->where('key', 'like', '%' . strtoupper($request->input('search')) . '%'))
                ->latest('id')->paginate(25);

            $keys->getCollection()->transform(fn (LicenseKey $k) => $k->toArray() + ['expired' => $k->status === 'available' && $k->isExpired()]);

            return response()->json($keys->toArray() + ['stats' => [
                'available' => LicenseKey::where('status', 'available')->count(),
                'redeemed' => LicenseKey::where('status', 'redeemed')->count(),
                'collected' => round((float) LicenseKey::where('status', '!=', 'revoked')->sum('amount'), 2),
            ]]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['required', Rule::in(Cycles::keys())],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:191'],
            'paid_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $keys = $this->context->withoutScoping(fn () => $this->licenses->generate($v, $request->user(), (int) ($v['count'] ?? 1)));
        activity('platform')->causedBy($request->user())->event('license_generated')
            ->withProperties(['count' => $keys->count(), 'plan_id' => $v['plan_id'], 'months' => $v['months'], 'company_id' => $v['company_id'] ?? null])
            ->log("Generated {$keys->count()} licence key(s)");

        return response()->json(['data' => $keys->map->load('plan:id,code,name', 'company:id,name'),
            'message' => $keys->count() === 1 ? 'Licence key generated.' : "{$keys->count()} licence keys generated."], 201);
    }

    public function revoke(Request $request, int $license): JsonResponse
    {
        $key = $this->context->withoutScoping(fn () => $this->licenses->revoke(LicenseKey::findOrFail($license)));
        activity('platform')->causedBy($request->user())->event('license_revoked')->log("Revoked licence {$key->masked()}");

        return response()->json(['data' => $key, 'message' => 'Licence key revoked.']);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => self::billingSettings()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['autopay_available' => ['required', 'boolean']]);
        PlatformSetting::put('billing.autopay_available', $v['autopay_available']);
        activity('platform')->causedBy($request->user())->event('billing_settings')->withProperties($v)
            ->log('Autopay ' . ($v['autopay_available'] ? 'made available to customers' : 'switched off for customers'));

        return response()->json(['data' => self::billingSettings(), 'message' => 'Billing settings saved.']);
    }

    public static function billingSettings(): array
    {
        return ['autopay_available' => (bool) PlatformSetting::get('billing.autopay_available', false)];
    }
}
