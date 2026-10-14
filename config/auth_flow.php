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

    /*
    | Account security (spec items 27–29).
    |  - max_sessions: devices an account can be signed in on at once (a phone and four more); past it the one used
    |    longest ago is signed out.
    |  - security_failed_attempts / security_code_requests: wrong codes or passwords, and sign-in codes asked for,
    |    within security_window_minutes that raise an alert to the owner (once per window).
    |  - security_log_days: how long the security log is kept.
    */
    'max_sessions' => (int) env('AUTH_MAX_SESSIONS', 5),
    'security_failed_attempts' => (int) env('AUTH_SECURITY_FAILED_ATTEMPTS', 3),
    'security_code_requests' => (int) env('AUTH_SECURITY_CODE_REQUESTS', 5),
    'security_window_minutes' => (int) env('AUTH_SECURITY_WINDOW_MINUTES', 15),
    'security_log_days' => (int) env('AUTH_SECURITY_LOG_DAYS', 365),
];
