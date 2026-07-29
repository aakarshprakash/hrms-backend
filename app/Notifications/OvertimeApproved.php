<?php

namespace App\Notifications;

use App\Models\OvertimeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OvertimeApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public OvertimeRequest $overtimeRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'overtime_approved',
            'overtime_request_id' => $this->overtimeRequest->id,
            'date' => $this->overtimeRequest->date?->toDateString(),
            'hours' => $this->overtimeRequest->hours,
            'message' => "Your overtime request for {$this->overtimeRequest->date?->toDateString()} has been approved.",
        ];
    }
}
