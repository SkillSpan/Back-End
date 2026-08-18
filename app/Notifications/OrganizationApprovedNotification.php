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
            ->subject('Your Organization Has Been Approved - SkillSpan')
            ->view('emails.organization-approved', [
                'name' => $notifiable->name,
                'organizationName' => $this->organization->name,
                'logoUrl' => asset('images/skillspan.jpg'),
            ]);
    }
}
