<?php

return [
    'otp_length' => (int) env('AUTH_OTP_LENGTH', 6),
    'otp_expiry_minutes' => (int) env('AUTH_OTP_EXPIRY_MINUTES', 10),
    'otp_max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
    'otp_resend_cooldown_seconds' => (int) env('AUTH_OTP_RESEND_COOLDOWN', 60),
    'flow_token_expiry_minutes' => (int) env('AUTH_FLOW_TOKEN_EXPIRY_MINUTES', 60),
];
