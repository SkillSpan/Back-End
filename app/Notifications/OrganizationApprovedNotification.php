<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationApprovedNotification extends Notification
{
    public function __construct(protected Organization $organization) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Organization Has Been Approved - SkillSpan')
            ->view('emails.organization-approved', [
                'name' => $notifiable->name,
                'organizationName' => $this->organization->name,
                'logoUrl' => asset('images/skillspan.jpg'),
            ]);
    }
}
