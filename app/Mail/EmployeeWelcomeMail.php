<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public string $plainPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to ' . config('app.name') . ' — Your Login Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.employee-welcome',
            with: [
                'employeeName' => $this->employee->full_name,
                'loginEmail' => $this->employee->email,
                'password' => $this->plainPassword,
                'loginUrl' => rtrim(config('app.frontend_url'), '/') . '/login',
            ],
        );
    }
}
