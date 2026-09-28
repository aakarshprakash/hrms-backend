<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Sends whatever is due in the notification outbox: messages whose
 * immediate send failed (retried with backoff) or never ran. Scheduled every
 * minute; safe to run concurrently with request-time sends.
 */
class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch {--limit=200}';

    protected $description = 'Deliver queued email / SMS / WhatsApp notifications';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $count = $dispatcher->deliverDue((int) $this->option('limit'));

        if ($count > 0) {
            $this->info("Attempted {$count} notification(s).");
        }

        return self::SUCCESS;
    }
}
