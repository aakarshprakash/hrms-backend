<?php

namespace App\Notifications;

use App\Models\AttendanceRegularization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RegularizationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AttendanceRegularization $regularization, public string $comments = '') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'regularization_rejected',
            'title' => 'Regularization Rejected',
            'body' => 'Your attendance regularization request has been rejected. ' . $this->comments,
            'link' => '/attendance/regularizations/' . $this->regularization->id,
        ];
    }
}
