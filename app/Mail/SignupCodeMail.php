<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-time code that verifies a new admin's email at sign-up. */
class SignupCodeMail extends Mailable
{
    public function __construct(public string $code, public int $minutes)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your " . config('app.name') . ' verification code');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.signup-code', with: ['code' => $this->code, 'minutes' => $this->minutes]);
    }
}
