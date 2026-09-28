<?php

namespace App\Services\Notifications\Gateways;

use App\Models\NotificationLog;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SMS through MSG91's Flow API. Indian SMS must use DLT-registered
 * templates: each event is mapped to a MSG91 flow (template) id whose
 * variables VAR1, VAR2 ... follow NotificationEvents' variable order.
 */
class Msg91Gateway implements MessageGateway
{
    public const ENDPOINT = 'https://control.msg91.com/api/v5/flow';

    public function name(): string
    {
        return 'msg91';
    }

    public function send(NotificationLog $log, array $credentials, array $options = []): ?string
    {
        $authKey = $credentials['auth_key'] ?? null;
        if (! $authKey) {
            throw new RuntimeException('MSG91 auth key is not set.');
        }
        if (! $log->template) {
            throw new RuntimeException("No MSG91 template (flow id) is set for {$log->event}.");
        }

        $recipient = ['mobiles' => Phone::digits($log->recipient)];
        foreach (array_values($log->variables['params'] ?? []) as $i => $value) {
            $recipient['VAR' . ($i + 1)] = mb_substr((string) $value, 0, 30);
        }

        $response = Http::timeout(15)
            ->withHeaders(['authkey' => $authKey, 'accept' => 'application/json'])
            ->post(self::ENDPOINT, array_filter([
                'template_id' => $log->template,
                'sender' => $credentials['sender_id'] ?? null,
                'short_url' => '0',
                'recipients' => [$recipient],
            ], fn ($v) => $v !== null));

        if ($response->failed() || $response->json('type') === 'error') {
            throw new RuntimeException('MSG91: ' . ($response->json('message') ?? $response->body()));
        }

        return (string) ($response->json('message') ?? '');
    }
}
