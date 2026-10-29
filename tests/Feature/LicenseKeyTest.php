<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Offline payment → licence key → plan activated; autopay switch. */
class LicenseKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));
        $this->seed(PlanSeeder::class);
        $this->platform = User::create(['name' => 'Ops', 'email' => 'ops@platform.test', 'password' => 'Secret123', 'is_super_admin' => true]);
    }

    /** @return array{company: Company, admin: User} */
    private function tenant(): array
    {
        $result = app(TenantProvisioner::class)->provision([
            'company_name' => 'Tenant ' . Str::random(5), 'state' => 'Kerala', 'apply_template' => false,
            'admin_name' => 'Owner', 'admin_email' => Str::lower(Str::random(8)) . '@owner.test', 'admin_password' => 'Secret123',
        ]);
        app(TenantContext::class)->reset();

        return ['company' => $result['company'], 'admin' => $result['admin']];
    }

    private function issue(array $overrides = []): string
    {
        return $this->actingAs($this->platform, 'sanctum')->postJson('/api/platform/licenses', array_merge([
            'plan_id' => Plan::where('code', 'growth')->value('id'), 'billing_cycle' => 'yearly', 'months' => 12,
            'amount' => 23600, 'payment_method' => 'bank_transfer', 'payment_reference' => 'UTR998877', 'paid_on' => '2026-09-30',
        ], $overrides))->assertCreated()->json('data.0.key');
    }

    private function current(Company $company): Subscription
    {
        return app(TenantContext::class)->runAs($company->id, fn () => Subscription::latest('id')->first());
    }

    public function test_a_key_activates_the_plan_and_books_a_paid_invoice(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        $key = $this->issue();
        $this->assertMatchesRegularExpression('/^PNX(-[2-9A-Z]{5}){4}$/', $key);

        // Typed in lower case without dashes still works.
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => strtolower(str_replace('-', '', $key))])->assertOk();

        $sub = $this->current($company);
        $this->assertSame('active', $sub->status);
        $this->assertSame('license', $sub->billing_mode);
        $this->assertSame('2027-09-30', $sub->current_period_end->toDateString());

        $invoice = app(TenantContext::class)->runAs($company->id, fn () => Invoice::where('subscription_id', $sub->id)->first());
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(23600, (float) $invoice->total);
        $this->assertEquals(20000, (float) $invoice->subtotal);      // GST-inclusive amount split back out
        $this->assertEquals(1800, (float) $invoice->cgst);            // same state as the seller: CGST + SGST
        $this->assertSame('UTR998877', $invoice->payment_reference);

        // A key works once.
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $key])
            ->assertStatus(422)->assertJsonPath('message', 'This licence key has already been used.');

        $billing = $this->actingAs($admin, 'sanctum')->getJson('/api/billing')->assertOk();
        $this->assertStringEndsWith(substr($key, -5), $billing->json('data.subscription.license.key'));
        $this->assertStringContainsString('••••', $billing->json('data.subscription.license.key'));
    }

    public function test_a_second_key_on_the_same_plan_extends_the_licence(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        [$year, $half] = [$this->issue(), $this->issue(['months' => 6, 'amount' => 0])];
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $year])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $half])->assertOk();

        $this->assertSame('2028-03-30', $this->current($company)->current_period_end->toDateString());
    }

    public function test_locked_revoked_and_expired_keys_are_refused(): void
    {
        ['admin' => $admin] = $this->tenant();
        ['company' => $other] = $this->tenant();

        $locked = $this->issue(['company_id' => $other->id]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $locked])
            ->assertStatus(422)->assertJsonPath('message', 'This licence key was issued to another organisation.');

        $key = $this->issue();
        $id = $this->actingAs($this->platform, 'sanctum')->getJson('/api/platform/licenses?search=' . substr($key, -5))->json('data.0.id');
        $this->actingAs($this->platform, 'sanctum')->postJson("/api/platform/licenses/{$id}/revoke")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $key])->assertStatus(422);

        $late = $this->issue(['valid_until' => '2026-10-05']);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $late])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson('/api/platform/licenses', [])->assertForbidden();
    }

    public function test_a_licence_is_not_invoiced_at_the_end_and_expires_after_grace(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        $key = $this->issue(['months' => 1, 'amount' => 0]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/billing/license', ['key' => $key])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        app(SubscriptionService::class)->runCycle();
        $this->assertSame('past_due', $this->current($company)->status);
        $this->assertSame(0, app(TenantContext::class)->runAs($company->id, fn () => Invoice::count()));

        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00', 'UTC'));
        app(SubscriptionService::class)->runCycle();
        $this->assertSame('expired', $this->current($company)->status);
    }

    public function test_autopay_switch_is_saved_and_the_platform_controls_availability(): void
    {
        ['admin' => $admin, 'company' => $company] = $this->tenant();
        $this->assertFalse($this->actingAs($admin, 'sanctum')->getJson('/api/billing')->json('data.autopay_available'));

        $this->actingAs($admin, 'sanctum')->putJson('/api/billing/autopay', ['autopay' => true])->assertOk()
            ->assertJsonPath('data.autopay', true);
        $this->assertTrue($this->current($company)->autopay);

        $this->actingAs($this->platform, 'sanctum')->putJson('/api/platform/billing/settings', ['autopay_available' => true])->assertOk();
        $this->assertTrue($this->actingAs($admin, 'sanctum')->getJson('/api/billing')->json('data.autopay_available'));
        $this->actingAs($admin, 'sanctum')->putJson('/api/platform/billing/settings', ['autopay_available' => false])->assertForbidden();
    }
}
