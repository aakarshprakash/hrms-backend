<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Mail\NotificationMail;
use App\Support\Mail\SmtpSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * The platform's outgoing mail server: sign-up codes, invoices, and every
 * organisation's emails unless it sets up its own. The password is
 * write-only.
 */
class PlatformMailController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present(), 'encryptions' => SmtpSettings::ENCRYPTIONS,
            'env_mailer' => config('mail.default') === 'platform_smtp' ? null : config('mail.default')]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:191'],
            'port' => ['required_if:enabled,true', 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(array_keys(SmtpSettings::ENCRYPTIONS))],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['required_if:enabled,true', 'nullable', 'email', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:191'],
        ]);
        SmtpSettings::savePlatform($v);
        activity('platform')->causedBy($request->user())->event('smtp_settings')
            ->withProperties(array_diff_key($v, ['password' => true]))->log('Platform SMTP settings ' . ($v['enabled'] ? 'saved' : 'switched off'));

        return response()->json(['data' => $this->present(), 'message' => $v['enabled']
            ? 'Mail server saved. Send a test to check it.' : 'Mail server switched off — the server’s .env mail settings apply.']);
    }

    /** Sends a test email through the saved settings, reporting the server's error if it fails. */
    public function test(Request $request): JsonResponse
    {
        $v = $request->validate(['to' => ['required', 'email']]);
        $smtp = SmtpSettings::platform(true);
        abort_unless($smtp && ! empty($smtp['host']), 422, 'Save the mail server settings first.');

        try {
            $mailer = SmtpSettings::register('platform_smtp_test', $smtp);
            $mail = new NotificationMail(mailSubject: 'Test email from ' . config('app.name'), heading: 'Your mail server works',
                lines: ['This is a test email sent through ' . $smtp['host'] . '.', 'Sign-up codes, invoices and notifications will be sent this way.']);
            $mail->from($smtp['from_address'], $smtp['from_name'] ?: config('app.name'));
            Mail::mailer($mailer)->to($v['to'])->send($mail);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The test email could not be sent: ' . $e->getMessage()], 422);
        }

        return response()->json(['message' => "Test email sent to {$v['to']} — check the inbox (and spam)."]);
    }

    private function present(): array
    {
        $s = SmtpSettings::platform(true) ?? [];

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'host' => $s['host'] ?? null,
            'port' => isset($s['port']) ? (int) $s['port'] : 587,
            'encryption' => $s['encryption'] ?? 'tls',
            'username' => $s['username'] ?? null,
            'password_set' => ! empty($s['password']),
            'from_address' => $s['from_address'] ?? null,
            'from_name' => $s['from_name'] ?? null,
        ];
    }
}
