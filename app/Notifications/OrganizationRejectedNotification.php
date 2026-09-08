<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationRejectedNotification extends Notification
{
    public function __construct(protected Organization $organization, protected ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update on Your Organization Registration - SkillSpan')
            ->view('emails.organization-rejected', [
                'name' => $notifiable->name,
                'organizationName' => $this->organization->name,
                'reason' => $this->reason,
                'logoUrl' => asset('images/skillspan.jpg'),
            ]);
    }
}
