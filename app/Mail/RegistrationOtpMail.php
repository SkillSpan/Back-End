<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $otp,
        public int $expiresInMinutes = 15
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SkillBridge — Verify your email (OTP)',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $name = e($this->user->name);
        $otp = e($this->otp);
        $minutes = $this->expiresInMinutes;

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Email Verification</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #222;">
  <h2>Welcome to SkillSpan, {$name}!</h2>
  <p>Use the following one-time password (OTP) to verify your email address:</p>
  <p style="font-size: 28px; font-weight: bold; letter-spacing: 4px;">{$otp}</p>
  <p>This code expires in <strong>{$minutes} minutes</strong>.</p>
  <p>If you did not create an account, you can ignore this email.</p>
  <hr>
  <p style="font-size: 12px; color: #666;">SkillSpan — From Education to the Labor Market</p>
</body>
</html>
HTML;
    }
}
