<?php

namespace App\Notifications;

use App\Models\AttendanceRegularization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RegularizationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AttendanceRegularization $regularization) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'regularization_submitted',
            'title' => 'New Regularization Request',
            'body' => 'An attendance regularization request has been submitted.',
            'link' => '/attendance/regularizations/' . $this->regularization->id,
        ];
    }
}
