<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use App\Support\Notifications\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Push notifications to the mobile app through Expo's push service, which
 * relays to FCM (Android) and APNs (iOS). Best effort: a failed push never
 * blocks anything -- the notification is still in the in-app bell.
 */
class ExpoPush
{
    /** Expo accepts at most 100 messages per request. */
    private const BATCH = 100;

    public function __construct(private TenantContext $context)
    {
    }

    /** @param  Collection<int, DeviceToken>  $devices */
    public function send(Collection $devices, NotificationMessage $message): void
    {
        if ($devices->isEmpty() || ! config('services.expo.push_enabled', true)) {
            return;
        }

        foreach ($devices->chunk(self::BATCH) as $chunk) {
            $chunk = $chunk->values();
            $payload = $chunk->map(fn (DeviceToken $device) => [
                'to' => $device->token,
                'title' => $message->title,
                'body' => $message->body,
                'sound' => 'default',
                'channelId' => 'default',
                'priority' => 'high',
                'data' => ['event' => $message->event, 'link' => $message->link],
            ])->all();

            try {
                $request = Http::acceptJson()->asJson()->timeout(8);
                if ($token = config('services.expo.access_token')) {
                    $request = $request->withToken($token);
                }
                $response = $request->post(config('services.expo.push_url'), $payload);
            } catch (\Throwable $e) {
                report($e);
                continue;
            }

            $this->forgetUnregistered($chunk, (array) $response->json('data', []));
        }
    }

    /**
     * Expo answers with one ticket per message, in order. A phone that
     * uninstalled the app reports DeviceNotRegistered: stop pushing to it.
     *
     * @param  Collection<int, DeviceToken>  $devices
     */
    private function forgetUnregistered(Collection $devices, array $tickets): void
    {
        $dead = collect($tickets)
            ->map(fn ($ticket, $i) => ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' ? $devices[$i]?->id : null)
            ->filter()
            ->values();

        if ($dead->isNotEmpty()) {
            $this->context->withoutScoping(fn () => DeviceToken::whereIn('id', $dead)->delete());
        }
    }
}
