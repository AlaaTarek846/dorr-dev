<?php

return [
    // 4 digits everywhere (phone login, e-mail codes, wallet-PIN recovery).
    'otp_length' => (int) env('AUTH_OTP_LENGTH', 4),
    'otp_expiry_minutes' => (int) env('AUTH_OTP_EXPIRY_MINUTES', 10),
    'otp_max_attempts' => (int) env('AUTH_OTP_MAX_ATTEMPTS', 5),
    'otp_resend_cooldown_seconds' => (int) env('AUTH_OTP_RESEND_COOLDOWN', 60),
    'flow_token_expiry_minutes' => (int) env('AUTH_FLOW_TOKEN_EXPIRY_MINUTES', 60),

    /*
    | Fixed demo OTP used by the mobile phone-auth flow (SMS gateway is not
    | wired yet). In production switch this to an env-provided random code.
    */
    'phone_otp_fixed' => env('AUTH_PHONE_OTP_FIXED', '1234'),

    /*
    | Development only: when set, e-mail codes (verification + wallet-PIN recovery) are this fixed
    | value instead of random, so the flow can be tested without a working mail server. Leave it
    | empty in production.
    */
    'email_otp_fixed' => env('AUTH_EMAIL_OTP_FIXED'),
];
