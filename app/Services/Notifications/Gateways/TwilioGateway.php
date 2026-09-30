<?php

namespace App\Services\Notifications\Gateways;

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SMS or WhatsApp through Twilio's Messages API. For WhatsApp,
 * business-initiated messages need an approved template: set a Content SID
 * per event and its variables {{1}}, {{2}} ... are filled in
 * NotificationEvents order; without one the plain text is sent (fine in the
 * Twilio sandbox or inside a 24-hour conversation window).
 */
class TwilioGateway implements MessageGateway
{
    public function __construct(private string $channel = 'sms')
    {
    }

    public function name(): string
    {
        return 'twilio';
    }

    public function send(NotificationLog $log, array $credentials, array $options = []): ?string
    {
        $sid = $credentials['account_sid'] ?? null;
        $token = $credentials['auth_token'] ?? null;
        $from = $credentials['from'] ?? null;
        if (! $sid || ! $token || ! $from) {
            throw new RuntimeException('Twilio account SID, auth token and sender are required.');
        }

        $whatsapp = $this->channel === 'whatsapp';
        $prefix = fn (string $n) => $whatsapp && ! str_starts_with($n, 'whatsapp:') ? "whatsapp:{$n}" : $n;

        $payload = ['To' => $prefix($log->recipient), 'From' => $prefix($from)];

        if ($whatsapp && $log->template) {
            $vars = [];
            foreach (array_values($log->variables['params'] ?? []) as $i => $value) {
                $vars[(string) ($i + 1)] = (string) $value;
            }
            $payload['ContentSid'] = $log->template;
            $payload['ContentVariables'] = json_encode($vars);
        } else {
            $payload['Body'] = $log->body;
        }

        $response = Http::timeout(15)->asForm()->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Twilio: ' . ($response->json('message') ?? $response->body()));
        }

        return $response->json('sid');
    }
}
