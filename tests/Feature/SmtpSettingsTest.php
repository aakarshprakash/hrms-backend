<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Branch;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Support\Mail\SmtpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Platform SMTP from the admin portal, and an organisation's own SMTP for its emails. */
class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = User::create(['name' => 'Ops', 'email' => 'ops@platform.test', 'password' => 'Secret123', 'is_super_admin' => true]);
    }

    public function test_platform_smtp_is_saved_with_a_write_only_password_and_becomes_the_default(): void
    {
        $this->actingAs($this->platform, 'sanctum')->putJson('/api/platform/mail-settings', [
            'enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'apikey', 'password' => 'super-secret', 'from_address' => 'no-reply@peoplenex.test', 'from_name' => 'PeopleNex',
        ])->assertOk()->assertJsonPath('data.password_set', true);

        $shown = $this->actingAs($this->platform, 'sanctum')->getJson('/api/platform/mail-settings')->assertOk();
        $this->assertStringNotContainsString('super-secret', $shown->getContent());
        $this->assertSame('smtp.example.com', $shown->json('data.host'));

        // Saving again without a password keeps it.
        $this->actingAs($this->platform, 'sanctum')->putJson('/api/platform/mail-settings', [
            'enabled' => true, 'host' => 'smtp2.example.com', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'no-reply@peoplenex.test',
        ])->assertOk();
        $this->assertSame('super-secret', SmtpSettings::platform()['password']);

        SmtpSettings::applyPlatformDefault();
        $this->assertSame('platform_smtp', config('mail.default'));
        $this->assertSame('smtp2.example.com', config('mail.mailers.platform_smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.platform_smtp.scheme'));
        $this->assertSame('no-reply@peoplenex.test', config('mail.from.address'));
    }

    public function test_a_failing_test_email_reports_the_error(): void
    {
        $this->actingAs($this->platform, 'sanctum')->putJson('/api/platform/mail-settings', [
            'enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'from_address' => 'no-reply@peoplenex.test',
        ])->assertOk();

        $this->actingAs($this->platform, 'sanctum')->postJson('/api/platform/mail-settings/test', ['to' => 'me@example.com'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'The test email could not be sent'));
    }

    public function test_only_the_platform_manages_its_mail_server(): void
    {
        $branch = $this->makeBranch($this->makeCompany('Mail Motors'));
        $admin = $this->makeUser('tenant_admin', $branch)['user'];
        $this->actingAs($admin, 'sanctum')->getJson('/api/platform/mail-settings')->assertForbidden();
    }

    public function test_an_organisation_can_send_its_emails_through_its_own_smtp(): void
    {
        $branch = $this->makeBranch($company = $this->makeCompany('Own Mail Motors'));
        $admin = $this->makeUser('tenant_admin', $branch)['user'];

        $this->actingAs($admin, 'sanctum')->putJson('/api/notification-settings', ['email_enabled' => true, 'smtp_enabled' => true, 'smtp_credentials' => ['host' => 'x']])
            ->assertStatus(422); // a From address is needed

        $this->actingAs($admin, 'sanctum')->putJson('/api/notification-settings', ['email_enabled' => true, 'smtp_enabled' => true, 'smtp_credentials' => [
            'host' => 'mail.ownmail.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'hr@ownmail.test', 'password' => 'p4ss',
            'from_address' => 'hr@ownmail.test', 'from_name' => 'Own Mail HR',
        ]])->assertOk()->assertJsonPath('data.smtp_enabled', true)
            ->assertJsonPath('data.smtp_credentials.host.value', 'mail.ownmail.test')
            ->assertJsonPath('data.smtp_credentials.password.value', null);

        $sender = SmtpSettings::forCompany($company->id, $company->name);
        $this->assertSame("company_smtp_{$company->id}", $sender['mailer']);
        $this->assertSame('mail.ownmail.test', config("mail.mailers.company_smtp_{$company->id}.host"));
        $this->assertSame('p4ss', config("mail.mailers.company_smtp_{$company->id}.password"));

        Mail::fake();
        $this->actingAs($admin, 'sanctum')->postJson('/api/notification-settings/test', ['channel' => 'email'])->assertOk();
        // (Mailable::hasFrom trips over an envelope without a sender, so check the address list itself.)
        Mail::assertSent(NotificationMail::class, fn ($m) => collect($m->from)->contains(fn ($f) => $f['address'] === 'hr@ownmail.test' && $f['name'] === 'Own Mail HR'));

        // Switched off: back to the platform sender under the organisation's name.
        NotificationSetting::where('company_id', $company->id)->update(['smtp_enabled' => false]);
        $this->assertNull(SmtpSettings::forCompany($company->id, $company->name)['mailer']);
        $this->assertStringContainsString('Own Mail Motors via', SmtpSettings::forCompany($company->id, $company->name)['from_name']);
    }
}
