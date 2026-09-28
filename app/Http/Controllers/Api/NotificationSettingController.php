<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Billing\Features;
use App\Support\Notifications\NotificationEvents;
use App\Support\Phone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organisation's notification setup (notifications.manage): channels,
 * providers and credentials, per-event channels and templates, a test
 * send, and the delivery log. Credentials are write-only: the API reports
 * which ones are set, never their values.
 */
class NotificationSettingController extends Controller
{
    /** Credential fields each provider needs (all strings). */
    public const CREDENTIAL_FIELDS = [
        'sms' => [
            'msg91' => ['auth_key' => 'Auth key', 'sender_id' => 'Sender ID (6 letters, DLT approved)'],
            'twilio' => ['account_sid' => 'Account SID', 'auth_token' => 'Auth token', 'from' => 'From number (+1…)'],
            'log' => [],
        ],
        'whatsapp' => [
            'meta' => ['access_token' => 'Permanent access token', 'phone_number_id' => 'Phone number ID'],
            'twilio' => ['account_sid' => 'Account SID', 'auth_token' => 'Auth token', 'from' => 'WhatsApp sender (+1…)'],
            'log' => [],
        ],
    ];

    /** Fields shown back unmasked (not secrets). */
    private const PUBLIC_FIELDS = ['sender_id', 'from', 'phone_number_id'];

    public function show(): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        $settings = NotificationSetting::for($company->id);

        return response()->json([
            'data' => $this->present($settings),
            'catalog' => NotificationEvents::catalog(),
            'providers' => [
                'sms' => NotificationSetting::SMS_PROVIDERS,
                'whatsapp' => NotificationSetting::WHATSAPP_PROVIDERS,
            ],
            'credential_fields' => self::CREDENTIAL_FIELDS,
            'plan_allows_messaging' => Features::has($company, 'notifications'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = app(TenantContext::class)->company();
        $channels = NotificationEvents::CHANNELS;

        $validated = $request->validate([
            'email_enabled' => 'sometimes|boolean',
            'sms_enabled' => 'sometimes|boolean',
            'sms_provider' => ['sometimes', 'nullable', Rule::in(array_keys(NotificationSetting::SMS_PROVIDERS))],
            'sms_credentials' => 'sometimes|array',
            'sms_credentials.*' => 'nullable|string|max:500',
            'whatsapp_enabled' => 'sometimes|boolean',
            'whatsapp_provider' => ['sometimes', 'nullable', Rule::in(array_keys(NotificationSetting::WHATSAPP_PROVIDERS))],
            'whatsapp_credentials' => 'sometimes|array',
            'whatsapp_credentials.*' => 'nullable|string|max:500',
            'events' => 'sometimes|array',
            'events.*.channels' => 'array',
            'events.*.channels.*' => [Rule::in($channels)],
            'events.*.sms_template' => 'nullable|string|max:100',
            'events.*.whatsapp_template' => 'nullable|string|max:100',
            'events.*.whatsapp_language' => 'nullable|string|max:10',
        ]);

        $wantsMessaging = ($validated['sms_enabled'] ?? false) || ($validated['whatsapp_enabled'] ?? false);
        abort_if($wantsMessaging && ! Features::has($company, 'notifications'), 403,
            'SMS and WhatsApp notifications are not included in your plan. Upgrade to switch them on.');

        $settings = NotificationSetting::query()->firstOrNew(['company_id' => $company->id]);

        foreach (['email_enabled', 'sms_enabled', 'sms_provider', 'whatsapp_enabled', 'whatsapp_provider'] as $field) {
            if (array_key_exists($field, $validated)) {
                $settings->{$field} = $validated[$field];
            }
        }

        foreach (['sms', 'whatsapp'] as $channel) {
            if (array_key_exists("{$channel}_credentials", $validated)) {
                $settings->{"{$channel}_credentials"} = $this->mergeCredentials(
                    $settings->credentialsFor($channel),
                    $validated["{$channel}_credentials"],
                    self::CREDENTIAL_FIELDS[$channel][$settings->{"{$channel}_provider"}] ?? [],
                );
            }
        }

        if (array_key_exists('events', $validated)) {
            $events = [];
            foreach ($validated['events'] as $key => $config) {
                if (! NotificationEvents::exists($key)) {
                    continue;
                }
                $events[$key] = array_filter([
                    'channels' => array_values(array_unique($config['channels'] ?? [])),
                    'sms_template' => $config['sms_template'] ?? null,
                    'whatsapp_template' => $config['whatsapp_template'] ?? null,
                    'whatsapp_language' => $config['whatsapp_language'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $events[$key]['channels'] ??= [];
            }
            $settings->events = $events;
        }

        $settings->company_id = $company->id;
        $settings->save();

        return response()->json(['data' => $this->present($settings->fresh()), 'message' => 'Notification settings saved.']);
    }

    /** Send a test message on one channel to the signed-in admin, right now. */
    public function test(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(NotificationEvents::EXTERNAL)],
            'to' => 'nullable|string|max:191',
        ]);

        $user = $request->user();
        $company = app(TenantContext::class)->company();
        $settings = NotificationSetting::for($company->id);
        $channel = $validated['channel'];

        abort_unless($settings->channelEnabled($channel), 422, 'Switch the channel on and save before sending a test.');

        $to = $channel === 'email'
            ? ($validated['to'] ?? $user->email)
            : Phone::e164($validated['to'] ?? $user->phone);
        abort_unless($to, 422, $channel === 'email' ? 'No email address to send to.' : 'Enter a mobile number to send the test to.');
        if ($channel === 'email') {
            abort_unless(filter_var($to, FILTER_VALIDATE_EMAIL), 422, 'That email address looks invalid.');
        }

        // Any event's template works for a test; prefer the leave decision's.
        $template = $channel === 'email' ? null : ($settings->templateFor('leave.decided', $channel)
            ?? collect(NotificationEvents::ALL)->keys()->map(fn ($e) => $settings->templateFor($e, $channel))->filter()->first());

        $log = NotificationLog::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'event' => 'leave.decided',
            'channel' => $channel,
            'recipient' => $to,
            'subject' => "Test notification · {$company->name}",
            'body' => "{$company->name}: this is a test notification from " . config('app.name') . '. If you received it, ' . $channel . ' notifications are working.',
            'template' => $template,
            'variables' => [
                'params' => [explode(' ', $user->name)[0], 'Casual Leave', now()->format('j M Y'), 'approved'],
                'title' => 'Test notification',
                'lines' => ['This is a test notification from ' . config('app.name') . '.', "If you received it, {$channel} notifications are working for {$company->name}."],
                'company' => $company->name,
            ],
            'status' => 'queued',
            'next_attempt_at' => now(),
        ]);

        $dispatcher->deliver($log);
        $log->refresh();

        return response()->json([
            'data' => $log->toListArray(),
            'message' => $log->status === 'sent' ? 'Test sent — check your ' . ($channel === 'email' ? 'inbox' : 'phone') . '.' : 'The test could not be sent: ' . ($log->error ?? $log->status),
        ], $log->status === 'sent' ? 200 : 422);
    }

    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => ['nullable', Rule::in(NotificationEvents::EXTERNAL)],
            'status' => ['nullable', Rule::in(NotificationLog::STATUSES)],
        ]);

        $page = NotificationLog::with('user:id,name')
            ->when($request->input('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map->toListArray(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function present(NotificationSetting $settings): array
    {
        $mask = function (string $channel) use ($settings) {
            $stored = $settings->credentialsFor($channel);
            $out = [];
            foreach ($stored as $key => $value) {
                $out[$key] = in_array($key, self::PUBLIC_FIELDS, true)
                    ? ['set' => $value !== null && $value !== '', 'value' => $value]
                    : ['set' => $value !== null && $value !== '', 'value' => null, 'hint' => $value ? '••••' . substr((string) $value, -4) : null];
            }

            return $out;
        };

        $events = [];
        foreach (NotificationEvents::ALL as $key => $meta) {
            $events[$key] = [
                'channels' => $settings->channelsFor($key),
                'sms_template' => $settings->templateFor($key, 'sms'),
                'whatsapp_template' => $settings->templateFor($key, 'whatsapp'),
                'whatsapp_language' => $settings->events[$key]['whatsapp_language'] ?? 'en',
            ];
        }

        return [
            'email_enabled' => (bool) $settings->email_enabled,
            'sms_enabled' => (bool) $settings->sms_enabled,
            'sms_provider' => $settings->sms_provider,
            'sms_credentials' => $mask('sms'),
            'whatsapp_enabled' => (bool) $settings->whatsapp_enabled,
            'whatsapp_provider' => $settings->whatsapp_provider,
            'whatsapp_credentials' => $mask('whatsapp'),
            'events' => $events,
        ];
    }

    /**
     * New credential values replace old ones; a blank value keeps what was
     * stored (the form never receives secrets back), and fields the provider
     * doesn't use are dropped.
     */
    private function mergeCredentials(array $stored, array $incoming, array $fields): array
    {
        $merged = [];
        foreach (array_keys($fields) as $field) {
            $value = $incoming[$field] ?? null;
            $merged[$field] = ($value === null || trim((string) $value) === '') ? ($stored[$field] ?? null) : trim((string) $value);
        }

        return array_filter($merged, fn ($v) => $v !== null);
    }
}
