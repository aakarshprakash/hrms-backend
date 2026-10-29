<?php

namespace App\Services\Notifications\Gateways;

use App\Mail\NotificationMail;
use App\Models\NotificationLog;
use App\Support\Mail\SmtpSettings;
use Illuminate\Support\Facades\Mail;

/** Email through the organisation's own SMTP if it has one, else the platform's mailer under the organisation's name. */
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

        $sender = SmtpSettings::forCompany($log->company_id, $v['company'] ?? null);
        $mail->from($sender['from_address'], $sender['from_name']);

        ($sender['mailer'] ? Mail::mailer($sender['mailer']) : Mail::mailer())->to($log->recipient)->send($mail);

        return null;
    }
}
