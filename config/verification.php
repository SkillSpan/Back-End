<?php

return [
    'otp_lifetime_minutes' => (int) env('OTP_LIFETIME_MINUTES', 30),
    'resend_interval_seconds' => (int) env('OTP_RESEND_INTERVAL', 60),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'otp_digits' => 6,
];
