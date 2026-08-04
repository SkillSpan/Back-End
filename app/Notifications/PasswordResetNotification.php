<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $otp)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('password_reset.otp_lifetime_minutes', 10);

        return (new MailMessage)
            ->subject('رمز إعادة تعيين كلمة المرور - SkillBridge')
            ->greeting('مرحباً ' . $notifiable->name)
            ->line('وصلنا طلب لإعادة تعيين كلمة المرور الخاصة بحسابك في SkillBridge.')
            ->line('يرجى استخدام الرمز التالي لإعادة تعيين كلمة المرور:')
            ->line('**' . $this->otp . '**')
            ->line("هذا الرمز صالح لمدة {$minutes} دقائق.")
            ->line('إذا لم تطلب إعادة تعيين كلمة المرور، يمكنك تجاهل هذه الرسالة.')
            ->salutation('مع تحيات فريق SkillBridge');
    }
}
