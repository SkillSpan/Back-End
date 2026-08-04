<?php

return [
    'otp_lifetime_minutes' => (int) env('PASSWORD_RESET_LIFETIME_MINUTES', 10),
    'resend_interval_seconds' => (int) env('PASSWORD_RESET_RESEND_INTERVAL', 60),
    'otp_digits' => 6,
];
