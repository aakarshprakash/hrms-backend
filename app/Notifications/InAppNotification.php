<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationMessage;
use Illuminate\Notifications\Notification;

/**
 * The in-app (bell) notification. Written synchronously with the change
 * that caused it; external channels go through the notification outbox.
 */
class InAppNotification extends Notification
{
    public function __construct(public NotificationMessage $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** Stored as the event key ("leave.decided") rather than a class name. */
    public function databaseType(object $notifiable): string
    {
        return $this->message->event;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => $this->message->event,
            'title' => $this->message->title,
            'body' => $this->message->body,
            'link' => $this->message->link,
        ];
    }
}
