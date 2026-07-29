<?php

namespace App\Notifications;

use App\Models\AttendanceRegularization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RegularizationApprovedNotification extends Notification implements ShouldQueue
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
            'type' => 'regularization_approved',
            'title' => 'Regularization Approved',
            'body' => 'Your attendance regularization request has been approved.',
            'link' => '/attendance/regularizations/' . $this->regularization->id,
        ];
    }
}
