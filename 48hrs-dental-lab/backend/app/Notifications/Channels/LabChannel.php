<?php

namespace App\Notifications\Channels;

use App\Models\UserNotification;
use Illuminate\Notifications\Notification;

/** Database channel writing the lab's notification rows (type, title, message, case). */
class LabChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $data = $notification->toLab($notifiable);
        UserNotification::create([
            'user_id' => $notifiable->getKey(),
            'type' => $data['type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'case_id' => $data['case_id'],
        ]);
    }
}
