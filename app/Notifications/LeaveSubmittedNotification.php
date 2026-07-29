<?php

namespace App\Notifications;

use App\Models\Leave;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeaveSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Leave $leave) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_submitted',
            'title' => 'New Leave Request',
            'body' => 'Employee ' . $this->leave->employee->user->name . ' has submitted a leave request.',
            'link' => '/leaves/' . $this->leave->id,
        ];
    }
}
