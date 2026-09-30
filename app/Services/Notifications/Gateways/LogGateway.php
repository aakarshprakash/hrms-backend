<?php

namespace App\Services\Notifications\Gateways;

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;

/** Test mode: records the message in the application log instead of sending it. */
class LogGateway implements MessageGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(NotificationLog $log, array $credentials, array $options = []): ?string
    {
        Log::info("[notification:{$log->channel}] to {$log->recipient}: {$log->body}", [
            'company_id' => $log->company_id,
            'event' => $log->event,
            'template' => $log->template,
            'params' => $log->variables['params'] ?? [],
        ]);

        return 'log-' . $log->id;
    }
}
