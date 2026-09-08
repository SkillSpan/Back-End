<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    public function __construct(protected string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('password_reset.otp_lifetime_minutes', 10);

        return (new MailMessage)
            ->subject('Password Reset Code - SkillSpan')
            ->view('emails.password-reset', [
                'name' => $notifiable->name,
                'otp' => $this->otp,
                'minutes' => $minutes,
                'logoUrl' => asset('images/skillspan.jpg'),
            ]);
    }
}
