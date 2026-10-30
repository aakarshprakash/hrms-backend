<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The mobile app's platform switch, plan module and per-organisation override. */
class MobileAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private const MOBILE = ['X-Client' => 'mobile-android', 'X-App-Version' => '1.0.0'];

    protected function setUp(): void
    {
        parent::setUp();
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

    private function login(User $user, array $headers = self::MOBILE)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($headers)->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Secret123']);
    }

    private function settings(array $values): void
    {
        $this->actingAs($this->platform, 'sanctum')
            ->putJson('/api/platform/mobile-settings', array_merge(['enabled' => true], $values))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app('billing.features')->forgetAll();
    }

    public function test_the_app_signs_in_when_the_plan_includes_it(): void
    {
        ['admin' => $admin] = $this->tenant();

        $this->login($admin)->assertOk()->assertJsonPath('data.mobile_app', true);
        $this->assertSame('ok', $this->getJson('/api/public/mobile-config')->assertOk()->json('data.enabled') ? 'ok' : 'closed');
    }

    public function test_the_platform_switch_closes_the_app_but_not_the_web(): void
    {
        ['admin' => $admin] = $this->tenant();
        $this->settings(['enabled' => false, 'message' => 'Back after maintenance.']);

        $this->login($admin)->assertForbidden()->assertJsonPath('code', 'MOBILE_DISABLED')->assertJsonPath('message', 'Back after maintenance.');
        $this->login($admin, [])->assertOk();
        $this->assertFalse($this->getJson('/api/public/mobile-config')->json('data.enabled'));
    }

    public function test_a_plan_without_the_app_refuses_it_even_for_a_signed_in_device(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->tenant();
        $token = $this->login($admin)->assertOk()->json('data.token');

        $planId = app(TenantContext::class)->runAs($company->id, fn () => \App\Models\Subscription::latest('id')->value('plan_id'));
        $this->settings(['plans' => [$planId => false]]);
        $this->assertNotContains('mobile_app', Plan::find($planId)->features);

        $this->withHeaders(self::MOBILE + ['Authorization' => "Bearer $token"])->getJson('/api/auth/me')
            ->assertForbidden()->assertJsonPath('code', 'MOBILE_NOT_IN_PLAN');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/auth/me')->assertOk();   // web still fine

        // Switched on for this one organisation regardless of the plan.
        $this->actingAs($this->platform, 'sanctum')->putJson("/api/platform/companies/{$company->id}/mobile-app", ['mobile_app' => 'on'])
            ->assertOk()->assertJsonPath('data.allowed', true);
        $this->login($admin)->assertOk();
    }

    public function test_an_old_app_version_must_update(): void
    {
        ['admin' => $admin] = $this->tenant();
        $this->settings(['min_version' => '1.2.0']);

        $this->login($admin)->assertForbidden()->assertJsonPath('code', 'APP_UPDATE_REQUIRED');
        $this->login($admin, ['X-Client' => 'mobile-android', 'X-App-Version' => '1.2.0'])->assertOk();
    }

    public function test_only_the_platform_admin_changes_the_switches(): void
    {
        ['admin' => $admin] = $this->tenant();

        $this->actingAs($admin, 'sanctum')->putJson('/api/platform/mobile-settings', ['enabled' => false])->assertForbidden();
    }
}
