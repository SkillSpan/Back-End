<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountVerificationNotification extends Notification
{
    public function __construct(protected string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('verification.otp_lifetime_minutes', 10);

        return (new MailMessage)
            ->subject('Your SkillSpan Verification Code')
            ->view('emails.otp-verification', [
                'name' => $notifiable->name,
                'otp' => $this->otp,
                'minutes' => $minutes,
                'logoUrl' => asset('images/skillspan.jpg'),
            ]);
    }
}
