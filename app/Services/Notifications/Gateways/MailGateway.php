<?php

namespace App\Services\Notifications\Gateways;

use App\Mail\NotificationMail;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Mail;

/** Email through the platform's configured mailer, from the tenant's name. */
class MailGateway implements MessageGateway
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(NotificationLog $log, array $credentials, array $options = []): ?string
    {
        $v = $log->variables ?? [];

        $mail = new NotificationMail(
            mailSubject: $log->subject ?? $v['title'] ?? config('app.name'),
            heading: $v['title'] ?? $log->subject ?? '',
            lines: $v['lines'] ?? [$log->body],
            link: $v['link'] ?? null,
            actionLabel: $v['action'] ?? null,
            companyName: $v['company'] ?? null,
        );

        if (! empty($v['company'])) {
            $mail->from(config('mail.from.address'), $v['company'] . ' via ' . config('app.name'));
        }

        Mail::to($log->recipient)->send($mail);

        return null;
    }
}
