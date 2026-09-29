<?php

namespace App\Notifications;

use App\Notifications\Channels\LabChannel;
use Illuminate\Notifications\Notification;

/**
 * An in-app notification (wording from App\Domain\LabMessages). Delivered by the
 * "lab" channel into the notifications table inside the caller's transaction, so
 * it appears with — and rolls back with — the change that caused it.
 */
class LabNotification extends Notification
{
    public function __construct(public readonly array $content, public readonly ?string $caseId = null) {}

    public function via(object $notifiable): array
    {
        return [LabChannel::class];
    }

    public function toLab(object $notifiable): array
    {
        return [...$this->content, 'case_id' => $this->caseId];
    }
}
