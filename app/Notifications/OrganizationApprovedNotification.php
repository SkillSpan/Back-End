<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Organization $organization)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Organization Has Been Approved - SkillBridge')
            ->greeting('Hello ' . $notifiable->name)
            ->line("Great news! Your organization, \"{$this->organization->name}\", has been reviewed and approved by the SkillBridge team.")
            ->line('Your organization account is now fully verified and you have complete access to the platform.')
            ->salutation('Best regards, The SkillBridge Team');
    }
}
