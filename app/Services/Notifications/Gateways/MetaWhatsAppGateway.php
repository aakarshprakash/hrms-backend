<?php

namespace App\Services\Notifications\Gateways;

use App\Models\NotificationLog;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Cloud API (Meta). Business-initiated messages must be approved
 * templates: each event is mapped to a template name (and language) whose
 * body variables {{1}}, {{2}} ... follow NotificationEvents' order.
 */
class MetaWhatsAppGateway implements MessageGateway
{
    public const GRAPH = 'https://graph.facebook.com/v20.0';

    public function name(): string
    {
        return 'meta';
    }

    public function send(NotificationLog $log, array $credentials, array $options = []): ?string
    {
        $token = $credentials['access_token'] ?? null;
        $phoneNumberId = $credentials['phone_number_id'] ?? null;
        if (! $token || ! $phoneNumberId) {
            throw new RuntimeException('WhatsApp access token and phone number id are required.');
        }
        if (! $log->template) {
            throw new RuntimeException("No WhatsApp template is set for {$log->event}.");
        }

        $parameters = array_map(
            fn ($value) => ['type' => 'text', 'text' => mb_substr((string) $value, 0, 60)],
            array_values($log->variables['params'] ?? [])
        );

        $response = Http::timeout(15)->withToken($token)->post(self::GRAPH . "/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => Phone::digits($log->recipient),
            'type' => 'template',
            'template' => [
                'name' => $log->template,
                'language' => ['code' => $options['language'] ?? 'en'],
                'components' => $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp: ' . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json('messages.0.id');
    }
}
