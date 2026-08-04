<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountVerificationNotification extends Notification implements ShouldQueue
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
        $minutes = (int) config('verification.otp_lifetime_minutes', 10);

        return (new MailMessage)
            ->subject('رمز التحقق - SkillBridge')
            ->greeting('مرحباً '.$notifiable->name)
            ->line('شكراً لتسجيلك في منصة SkillBridge.')
            ->line('يرجى استخدام الرمز التالي لتأكيد حسابك:')
            ->line('**'.$this->otp.'**')
            ->line("هذا الرمز صالح لمدة {$minutes} دقائق.")
            ->line('إذا لم تقم بإنشاء هذا الحساب، يمكنك تجاهل هذه الرسالة.')
            ->salutation('مع تحيات فريق SkillBridge');
    }
}
