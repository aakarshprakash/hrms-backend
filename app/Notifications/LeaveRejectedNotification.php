<?php

namespace App\Notifications;

use App\Models\Leave;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeaveRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Leave $leave, public string $comments = '') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_rejected',
            'title' => 'Leave Rejected',
            'body' => 'Your leave request has been rejected. ' . $this->comments,
            'link' => '/leaves/' . $this->leave->id,
        ];
    }
}
