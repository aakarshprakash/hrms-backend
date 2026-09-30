<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_self_serve_signup_is_off_unless_enabled(): void
    {
        $payload = ['company_name' => 'New Motors', 'admin_name' => 'Asha', 'admin_email' => 'asha@newmotors.test', 'admin_password' => 'Secret123', 'state' => 'Kerala'];

        $this->postJson('/api/auth/signup', $payload)->assertNotFound();
        $this->assertFalse($this->getJson('/api/public/config')->json('data.signup'));

        config(['billing.public_signup' => true]);
        $response = $this->postJson('/api/auth/signup', $payload)->assertCreated();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('trialing', $response->json('data.subscription.status'));
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
