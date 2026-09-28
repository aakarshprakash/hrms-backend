<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Services\Notifications\Gateways\LogGateway;
use App\Services\Notifications\Gateways\MailGateway;
use App\Services\Notifications\Gateways\MessageGateway;
use App\Services\Notifications\Gateways\MetaWhatsAppGateway;
use App\Services\Notifications\Gateways\Msg91Gateway;
use App\Services\Notifications\Gateways\TwilioGateway;
use App\Support\Billing\Features;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Delivers the notification outbox. Each row is claimed atomically
 * (queued → sending) so the post-response send and the scheduler never
 * both send it; failures retry with backoff (2, 4, 8, 16 minutes) and give
 * up after five attempts, keeping the provider's error for the log.
 */
class NotificationDispatcher
{
    public const MAX_ATTEMPTS = 5;

    /** @param  list<int>  $ids */
    public function deliverIds(array $ids): void
    {
        foreach (NotificationLog::whereIn('id', $ids)->where('status', 'queued')->get() as $log) {
            $this->deliver($log);
        }
    }

    /** Scheduler: everything due, across tenants. Returns how many were attempted. */
    public function deliverDue(int $limit = 200): int
    {
        // A worker that died mid-send leaves rows in "sending": try them again.
        NotificationLog::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'queued', 'next_attempt_at' => now()]);

        $due = NotificationLog::where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $context = app(TenantContext::class);
        foreach ($due as $log) {
            $context->runAs($log->company_id, fn () => $this->deliver($log));
        }

        return $due->count();
    }

    public function deliver(NotificationLog $log): void
    {
        $claimed = NotificationLog::whereKey($log->id)->where('status', 'queued')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $log->refresh();

        $settings = NotificationSetting::for($log->company_id);

        if (! $settings->channelEnabled($log->channel)) {
            $log->update(['status' => 'skipped', 'error' => ucfirst($log->channel) . ' notifications are switched off.']);

            return;
        }
        if ($log->channel !== 'email' && ! $this->planAllows($log->company_id)) {
            $log->update(['status' => 'skipped', 'error' => 'SMS / WhatsApp notifications are not included in the current plan.']);

            return;
        }

        try {
            $gateway = $this->gateway($log->channel, $settings->providerFor($log->channel));
            $messageId = $gateway->send($log, $settings->credentialsFor($log->channel), [
                'language' => $settings->languageFor($log->event),
            ]);

            $log->update([
                'status' => 'sent', 'provider' => $gateway->name(), 'provider_message_id' => $messageId,
                'sent_at' => now(), 'error' => null, 'next_attempt_at' => null,
            ]);
        } catch (\Throwable $e) {
            $final = $log->attempts >= self::MAX_ATTEMPTS;
            $log->update([
                'status' => $final ? 'failed' : 'queued',
                'provider' => $settings->providerFor($log->channel),
                'error' => mb_substr($e->getMessage(), 0, 500),
                'next_attempt_at' => $final ? null : now()->addMinutes(2 ** $log->attempts),
            ]);
        }
    }

    public function gateway(string $channel, ?string $provider): MessageGateway
    {
        return match (true) {
            $channel === 'email' => new MailGateway(),
            $provider === 'log' => new LogGateway(),
            $channel === 'sms' && $provider === 'msg91' => new Msg91Gateway(),
            $channel === 'whatsapp' && $provider === 'meta' => new MetaWhatsAppGateway(),
            $provider === 'twilio' => new TwilioGateway($channel),
            default => throw new \RuntimeException("No {$channel} provider is configured."),
        };
    }

    private function planAllows(int $companyId): bool
    {
        $company = app(TenantContext::class)->withoutScoping(fn () => \App\Models\Company::find($companyId));

        return $company !== null && Features::has($company, 'notifications');
    }
}
