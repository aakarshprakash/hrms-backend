<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email version of a notification, sent from the notification outbox. */
class NotificationMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $mailSubject,
        public string $heading,
        public array $lines,
        public ?string $link = null,
        public ?string $actionLabel = null,
        public ?string $companyName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notification',
            with: [
                'heading' => $this->heading,
                'lines' => $this->lines,
                'url' => $this->link ? rtrim((string) config('app.frontend_url'), '/') . $this->link : null,
                'actionLabel' => $this->actionLabel ?? 'Open in ' . config('app.name'),
                'companyName' => $this->companyName,
            ],
        );
    }
}
