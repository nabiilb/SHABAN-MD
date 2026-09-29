<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The password-reset e-mail. Queued: the web request never waits for SMTP; a failure lands in failed_jobs. */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('lab.frontend_url').'/reset-password?token='.rawurlencode($this->token).'&email='.rawurlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Reset your 48HRS Dental Lab password')
            ->greeting("Hello {$notifiable->name},")
            ->line('Use this link within one hour to choose a new password:')
            ->action('Choose a new password', $url)
            ->line('If you did not ask for this, ignore this e-mail.');
    }
}
