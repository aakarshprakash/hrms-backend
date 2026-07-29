<?php

namespace App\Notifications;

use App\Models\OvertimeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OvertimeSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public OvertimeRequest $overtimeRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->overtimeRequest->employee;
        return [
            'type' => 'overtime_submitted',
            'overtime_request_id' => $this->overtimeRequest->id,
            'employee_id' => $this->overtimeRequest->employee_id,
            'employee_name' => $employee ? "{$employee->first_name} {$employee->last_name}" : null,
            'date' => $this->overtimeRequest->date?->toDateString(),
            'hours' => $this->overtimeRequest->hours,
            'message' => "New overtime request submitted by " . ($employee ? $employee->first_name : 'employee'),
        ];
    }
}
