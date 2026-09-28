<?php

namespace App\Services\Notifications\Gateways;

use App\Models\NotificationLog;

/**
 * Sends one outbox message through a provider. Returns the provider's
 * message id (if any); throws on failure so the dispatcher can retry.
 */
interface MessageGateway
{
    public function name(): string;

    /** @param  array<string, mixed>  $credentials */
    public function send(NotificationLog $log, array $credentials, array $options = []): ?string;
}
