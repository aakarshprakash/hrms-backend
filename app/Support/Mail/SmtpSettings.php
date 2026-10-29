<?php

namespace App\Support\Mail;

use App\Models\NotificationSetting;
use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * SMTP from the database instead of .env: the platform's server (used for
 * sign-up codes, invoices and as every organisation's default) and an
 * organisation's own server for its emails. Each becomes a named mailer at
 * runtime, so a queued job picks the right one per message.
 */
class SmtpSettings
{
    public const FIELDS = ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    public const ENCRYPTIONS = ['tls' => 'TLS (STARTTLS, usually port 587)', 'ssl' => 'SSL (usually port 465)', 'none' => 'None (port 25)'];

    private const PLATFORM_KEY = 'mail.smtp';

    /** The platform's SMTP (password decrypted), or null when not set up / switched off. */
    public static function platform(bool $includeDisabled = false): ?array
    {
        $s = PlatformSetting::get(self::PLATFORM_KEY);
        if (! is_array($s) || (! $includeDisabled && empty($s['enabled']))) {
            return null;
        }
        if (! empty($s['password'])) {
            try {
                $s['password'] = Crypt::decryptString($s['password']);
            } catch (DecryptException) {
                $s['password'] = null;
            }
        }

        return $s;
    }

    public static function savePlatform(array $values): void
    {
        $current = self::platform(true) ?? [];
        $merged = array_merge($current, array_filter($values, fn ($v) => $v !== null && $v !== ''), ['enabled' => (bool) ($values['enabled'] ?? false)]);
        if (array_key_exists('encryption', $values)) {
            $merged['encryption'] = $values['encryption'];
        }
        if (! empty($merged['password'])) {
            $merged['password'] = Crypt::encryptString($merged['password']);
        }
        PlatformSetting::put(self::PLATFORM_KEY, $merged);
    }

    /** An organisation's own SMTP, when switched on and complete. */
    public static function company(?int $companyId): ?array
    {
        if (! $companyId || ! Schema::hasColumn('notification_settings', 'smtp_enabled')) {
            return null;
        }
        $settings = NotificationSetting::query()->where('company_id', $companyId)->first();
        if (! $settings?->smtp_enabled) {
            return null;
        }
        $c = (array) ($settings->smtp_credentials ?? []);

        return ! empty($c['host']) && ! empty($c['from_address']) ? $c : null;
    }

    /** Registers a mailer for $smtp and returns its name. */
    public static function register(string $name, array $smtp): string
    {
        $encryption = $smtp['encryption'] ?? 'tls';
        config([
            "mail.mailers.{$name}" => [
                'transport' => 'smtp',
                'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $smtp['host'],
                'port' => (int) ($smtp['port'] ?? ($encryption === 'ssl' ? 465 : 587)),
                'username' => $smtp['username'] ?? null,
                'password' => $smtp['password'] ?? null,
                'timeout' => 20,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            ],
        ]);
        app('mail.manager')->purge($name);

        return $name;
    }

    /**
     * Makes the platform SMTP the app's default mailer (called at boot).
     * Leaves .env alone when nothing is saved, or the tables don't exist yet.
     */
    public static function applyPlatformDefault(): void
    {
        try {
            if (! Schema::hasTable('platform_settings') || ! ($smtp = self::platform())) {
                return;
            }
        } catch (\Throwable) {
            return; // no database (e.g. during install)
        }
        self::register('platform_smtp', $smtp);
        config(['mail.default' => 'platform_smtp', 'mail.from.address' => $smtp['from_address'] ?? config('mail.from.address'),
            'mail.from.name' => $smtp['from_name'] ?: config('mail.from.name')]);
    }

    /**
     * Mailer + sender for an organisation's emails: its own SMTP, else the
     * platform default (with the organisation's name on the sender).
     *
     * @return array{mailer: ?string, from_address: string, from_name: ?string}
     */
    public static function forCompany(?int $companyId, ?string $companyName = null): array
    {
        if ($own = self::company($companyId)) {
            return ['mailer' => self::register("company_smtp_{$companyId}", $own), 'from_address' => $own['from_address'],
                'from_name' => $own['from_name'] ?: $companyName];
        }

        return ['mailer' => null, 'from_address' => (string) config('mail.from.address'),
            'from_name' => $companyName ? $companyName . ' via ' . config('app.name') : config('mail.from.name')];
    }
}
