<?php

namespace App\Support\Notifications;

/**
 * One notification, ready for every channel: a title and short body (in-app,
 * SMS, WhatsApp session text), an email subject and lines, the link into
 * the app, and the ordered template variables for SMS / WhatsApp templates.
 */
final class NotificationMessage
{
    /**
     * @param  list<string>  $params  template variables, in NotificationEvents order
     * @param  list<string>  $lines  email paragraphs
     */
    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
        public readonly array $params = [],
        public readonly array $lines = [],
        public readonly ?string $actionLabel = null,
        public readonly ?string $companyName = null,
    ) {
    }

    public function subject(): string
    {
        return $this->companyName ? "{$this->title} · {$this->companyName}" : $this->title;
    }

    /** Plain text for SMS / WhatsApp when no template is configured. */
    public function text(): string
    {
        $url = $this->link ? rtrim((string) config('app.frontend_url'), '/') . $this->link : null;

        return trim(($this->companyName ? "{$this->companyName}: " : '') . $this->body . ($url ? " {$url}" : ''));
    }
}
