<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Organization $organization, protected ?string $reason = null)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Update on Your Organization Registration - SkillBridge')
            ->greeting('Hello ' . $notifiable->name)
            ->line("We have reviewed your organization registration for \"{$this->organization->name}\" and, unfortunately, we are unable to approve it at this time.");

        if ($this->reason) {
            $message->line("Reason: {$this->reason}");
        }

        return $message
            ->line('If you believe this is a mistake or you can provide additional verification documents, please contact our support team.')
            ->salutation('Best regards, The SkillBridge Team');
    }
}
