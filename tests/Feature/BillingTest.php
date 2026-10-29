<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Mail\SignupCodeMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Plans decide modules and quotas; trials, renewals, GST invoices and payment. */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));
        $this->seed(PlanSeeder::class);
    }

    /** @return array{company: Company, admin: User, branch: Branch} */
    private function tenant(string $state = 'Kerala', ?string $plan = null): array
    {
        $result = app(TenantProvisioner::class)->provision([
            'company_name' => 'Tenant ' . Str::random(5), 'state' => $state, 'apply_template' => false, 'plan_code' => $plan,
            'admin_name' => 'Owner', 'admin_email' => Str::lower(Str::random(8)) . '@owner.test', 'admin_password' => 'Secret123',
        ]);
        app(TenantContext::class)->reset();

        return ['company' => $result['company'], 'admin' => $result['admin'], 'branch' => $result['branch']];
    }

    private function employees(Company $company, Branch $branch, int $count): void
    {
        app(TenantContext::class)->runAs($company->id, fn () => Employee::factory()->count($count)->create(['branch_id' => $branch->id, 'status' => 'active']));
    }

    private function cycle(): array
    {
        return app(SubscriptionService::class)->runCycle();
    }

    private function features(Company $company): array
    {
        app(\App\Services\Billing\BillingFeatureResolver::class)->forget($company->id);

        return Features::enabledFor($company->fresh());
    }

    public function test_founding_customers_keep_every_module_and_are_never_invoiced(): void
    {
        $company = $this->makeCompany('Early Bird');
        $this->seed(PlanSeeder::class); // the deploy after billing ships

        $this->assertSame('legacy', app(SubscriptionService::class)->current($company)->plan->code);
        $this->assertEqualsCanonicalizing(array_keys(Features::ALL), $this->features($company));

        $this->travel(40)->days();
        $this->cycle();
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_unconfirmed_trial_falls_back_to_the_free_core(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();

        $this->assertContains('payroll', $this->features($company));
        $this->assertNotContains('insights', $this->features($company)); // Growth trial

        $this->travel(15)->days();
        $this->cycle();

        $this->assertSame('expired', app(SubscriptionService::class)->current($company)->status);
        $this->assertEqualsCanonicalizing(['core', 'self_service'], $this->features($company));
        $this->actingAs($admin, 'sanctum')->getJson('/api/payroll-runs')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
        $this->actingAs($admin, 'sanctum')->getJson('/api/leaves')->assertOk();
    }

    public function test_a_plan_chosen_in_the_trial_is_billed_when_it_ends_with_gst(): void
    {
        ['company' => $company, 'admin' => $admin, 'branch' => $branch] = $this->tenant('Kerala');
        $this->employees($company, $branch, 30); // 5 beyond Growth's 25 included

        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'growth', 'billing_cycle' => 'monthly'])
            ->assertOk()->assertJsonPath('data.status', 'trialing');

        $this->travel(14)->days();
        $this->cycle();

        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(1999 + 5 * 69, (float) $invoice->subtotal);
        $this->assertEquals(210.96, (float) $invoice->cgst); // Kerala → Kerala: CGST + SGST
        $this->assertEquals(210.96, (float) $invoice->sgst);
        $this->assertEquals(0, (float) $invoice->igst);
        $this->assertEquals(2765.92, (float) $invoice->total);
        $this->assertMatchesRegularExpression('/^PNX-2026-\d{6}$/', $invoice->number);
        $this->assertSame('active', app(SubscriptionService::class)->current($company)->status);

        $pdf = $this->actingAs($admin, 'sanctum')->get("/api/billing/invoices/{$invoice->id}/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_other_states_pay_igst_and_overdue_invoices_go_past_due_until_paid(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant('Karnataka');
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'monthly'])->assertOk();
        $this->travel(14)->days();
        $this->cycle();

        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(round(999 * 0.18, 2), (float) $invoice->igst);
        $this->assertEquals(0, (float) $invoice->cgst);

        $this->travel(15)->days(); // 7 days to pay + 7 days grace
        $this->cycle();
        $this->assertSame('past_due', app(SubscriptionService::class)->current($company)->status);

        $platform = User::create(['name' => 'Ops', 'email' => 'ops@platform.test', 'password' => 'Secret123', 'is_super_admin' => true]);
        $this->actingAs($platform, 'sanctum')->postJson("/api/platform/invoices/{$invoice->id}/mark-paid", ['payment_method' => 'bank_transfer', 'payment_reference' => 'UTR123'])
            ->assertOk();
        $this->assertSame('active', app(SubscriptionService::class)->current($company)->status);
    }

    public function test_plans_enforce_quotas_and_a_downgrade_must_fit(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        app(TenantContext::class)->runAs($company->id, fn () => Branch::create(['company_id' => $company->id, 'name' => 'Second', 'timezone' => 'Asia/Kolkata', 'currency_code' => 'INR']));

        // Starter allows one branch.
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'monthly'])
            ->assertStatus(422);

        Plan::where('code', 'growth')->update(['limits' => json_encode(['branches' => 2, 'employees' => 50])]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/branches', ['name' => 'Third'])->assertStatus(402);
    }

    /** Sends a sign-up code and returns it as emailed. */
    private function emailedCode(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/auth/signup/send-code', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(SignupCodeMail::class, function (SignupCodeMail $mail) use ($email, &$code) {
            $code = $mail->code;

            return $mail->hasTo($email);
        });

        return $code;
    }

    private function signupPayload(string $email, string $token, array $extra = []): array
    {
        return $extra + ['company_name' => 'New Motors', 'admin_name' => 'Asha Nair', 'admin_email' => $email, 'admin_password' => 'Secret123',
            'state' => 'Kerala', 'plan_code' => 'growth', 'billing_cycle' => 'monthly', 'verification_token' => $token];
    }

    public function test_self_serve_signup_is_off_unless_enabled(): void
    {
        config(['billing.public_signup' => false]);

        $this->postJson('/api/auth/signup', $this->signupPayload('asha@newmotors.test', 'x'))->assertNotFound();
        $this->postJson('/api/auth/signup/send-code', ['email' => 'asha@newmotors.test'])->assertNotFound();
        $this->assertFalse($this->getJson('/api/public/config')->json('data.signup'));
    }

    public function test_signup_verifies_the_email_by_otp_then_starts_a_trial_on_the_chosen_plan(): void
    {
        config(['billing.public_signup' => true]);
        $email = 'asha@newmotors.test';

        // No token, or a made-up one: refused.
        $this->postJson('/api/auth/signup', $this->signupPayload($email, 'forged'))->assertStatus(422);

        $code = $this->emailedCode($email);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // Asking again straight away is throttled.
        $this->postJson('/api/auth/signup/send-code', ['email' => $email])->assertStatus(422)->assertJsonValidationErrors('email');

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/auth/signup/verify-code', ['email' => $email, 'code' => $wrong])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $token = $this->postJson('/api/auth/signup/verify-code', ['email' => $email, 'code' => $code])
            ->assertOk()->json('data.verification_token');

        $response = $this->postJson('/api/auth/signup', $this->signupPayload($email, $token, ['billing_cycle' => 'half_yearly']))->assertCreated();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('trialing', $response->json('data.subscription.status'));
        $this->assertSame('half_yearly', $response->json('data.subscription.billing_cycle'));
        $this->assertTrue($response->json('data.subscription.plan_confirmed'));
        $this->assertNotNull(User::where('email', $email)->value('email_verified_at'));

        // The token is spent, and the email is now taken.
        $this->postJson('/api/auth/signup', $this->signupPayload($email, $token, ['company_name' => 'Again']))->assertStatus(422);
        $this->postJson('/api/auth/signup/send-code', ['email' => $email])->assertStatus(422);
    }

    public function test_five_wrong_codes_kill_the_code(): void
    {
        config(['billing.public_signup' => true]);
        $code = $this->emailedCode('guess@example.test');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/signup/verify-code', ['email' => 'guess@example.test', 'code' => $wrong])->assertStatus(422);
        }
        $this->postJson('/api/auth/signup/verify-code', ['email' => 'guess@example.test', 'code' => $code])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Too many wrong attempts. Request a new code.');
    }

    public function test_a_six_month_cycle_bills_five_and_a_half_months_for_six(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant('Kerala');
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'half_yearly'])->assertOk();
        $this->travel(14)->days();
        $this->cycle();

        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(999 * 5.5, (float) $invoice->subtotal);
        $this->assertSame('2027-04-14', $invoice->period_end->toDateString());
    }

    public function test_skipping_the_trial_starts_billing_today(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'yearly', 'start_now' => true])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertEquals(round(999 * 10 * 1.18, 2), (float) $response->json('invoice.total'));
        $this->assertSame('active', app(SubscriptionService::class)->current($company)->status);
    }

    public function test_razorpay_checkout_is_verified_by_signature_and_pays_the_invoice_once(): void
    {
        config(['billing.razorpay.key_id' => 'rzp_test_key', 'billing.razorpay.key_secret' => 'secret']);
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_ABC', 'amount' => 117882, 'currency' => 'INR']),
            'api.razorpay.com/v1/payments/*' => Http::response(['method' => 'upi']),
        ]);
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        ['admin' => $other] = $this->tenant();
        $invoiceId = $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'monthly', 'start_now' => true])
            ->json('invoice.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/billing/invoices/{$invoiceId}/checkout")
            ->assertOk()->assertJsonPath('data.order_id', 'order_ABC')->assertJsonPath('data.key_id', 'rzp_test_key');

        $paid = ['razorpay_order_id' => 'order_ABC', 'razorpay_payment_id' => 'pay_XYZ'];
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/payments/verify', $paid + ['razorpay_signature' => 'forged'])->assertStatus(422);
        $this->actingAs($other, 'sanctum')->postJson('/api/billing/payments/verify', $paid + ['razorpay_signature' => hash_hmac('sha256', 'order_ABC|pay_XYZ', 'secret')])
            ->assertNotFound();

        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/payments/verify', $paid + ['razorpay_signature' => hash_hmac('sha256', 'order_ABC|pay_XYZ', 'secret')])
            ->assertOk()->assertJsonPath('data.status', 'captured')->assertJsonPath('data.method', 'upi');

        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->findOrFail($invoiceId);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('pay_XYZ', $invoice->payment_reference);

        // The webhook arriving afterwards changes nothing.
        config(['billing.razorpay.webhook_secret' => 'whsec']);
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_XYZ', 'order_id' => 'order_ABC', 'method' => 'upi']]]]);
        $this->call('POST', '/api/billing/razorpay/webhook', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();
        $this->assertSame(1, Payment::where('status', 'captured')->count());
    }

    public function test_only_the_platform_sees_revenue_across_clients(): void
    {
        ['admin' => $admin] = $this->tenant();
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'yearly', 'start_now' => true]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/platform/billing/overview')->assertForbidden();

        $platform = User::create(['name' => 'Ops', 'email' => 'ops@platform.test', 'password' => 'Secret123', 'is_super_admin' => true]);
        $overview = $this->actingAs($platform, 'sanctum')->getJson('/api/platform/billing/overview')->assertOk();
        $this->assertEquals(999 * 10 / 12, $overview->json('data.mrr'));
        $this->assertEquals(round(999 * 10 * 1.18, 2), $overview->json('data.outstanding'));
        $this->actingAs($platform, 'sanctum')->getJson('/api/platform/billing/invoices?status=open')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_razorpay_webhook_needs_a_valid_signature(): void
    {
        config(['billing.razorpay.webhook_secret' => 'whsec']);
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'monthly']);
        $this->travel(14)->days();
        $this->cycle();
        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->where('company_id', $company->id)->firstOrFail();

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['reference_id' => $invoice->number]],
            'payment' => ['entity' => ['id' => 'pay_123']],
        ]]);

        $this->call('POST', '/api/billing/razorpay/webhook', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(400);
        $this->call('POST', '/api/billing/razorpay/webhook', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('pay_123', $invoice->fresh()->payment_reference);
    }

    public function test_invoices_stay_inside_their_tenant(): void
    {
        ['company' => $a, 'admin' => $adminA] = $this->tenant();
        ['admin' => $adminB] = $this->tenant();
        $this->actingAs($adminA, 'sanctum')->postJson('/api/billing/plan', ['plan_code' => 'starter', 'billing_cycle' => 'monthly']);
        $this->travel(14)->days();
        $this->cycle();
        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->where('company_id', $a->id)->firstOrFail();

        $this->actingAs($adminB, 'sanctum')->get("/api/billing/invoices/{$invoice->id}/pdf")->assertNotFound();
        $this->assertSame(0, $this->actingAs($adminB, 'sanctum')->getJson('/api/billing/invoices')->json('meta.total'));
    }
}
