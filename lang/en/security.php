<?php

// Account security: devices signed in on the account and its security log (app + dashboard).
return [
    'unknown_device' => 'Unknown device',
    'signed_out' => 'The device was signed out.',
    'signed_out_others' => ':count other device(s) signed out.',

    'platforms' => [
        'android' => 'Android',
        'ios' => 'iPhone',
        'web' => 'Web',
        'unknown' => 'Unknown',
    ],

    'flows' => [
        'login' => 'Sign in',
        'restore' => 'Account restore',
        'resend' => 'Code resent',
    ],

    'events' => [
        'login' => 'Signed in',
        'login_new_device' => 'Signed in from a new device',
        'login_failed' => 'Wrong password',
        'otp_requested' => 'Sign-in code requested',
        'otp_failed' => 'Wrong sign-in code',
        'otp_locked' => 'Too many wrong codes',
        'logout' => 'Signed out',
        'device_signed_out' => 'Device signed out',
        'other_devices_signed_out' => 'Other devices signed out',
        'session_limit' => 'Oldest device signed out (device limit)',
        'admin_signed_out' => 'Signed out by support',
        'phone_changed' => 'Phone number changed',
        'pin_failed' => 'Wrong wallet PIN',
        'pin_locked' => 'Wallet temporarily locked',
        'pin_frozen' => 'Wallet frozen',
        'account_restored' => 'Account restored',
    ],

    'details' => [
        'otp_requested' => 'Flow: :flow',
        'device_signed_out' => 'Device: :device',
        'other_devices_signed_out' => ':count device(s)',
        'session_limit' => 'Device: :device',
        'admin_signed_out' => 'Device: :device',
        'admin_signed_out_all' => ':count device(s)',
        'phone_changed' => 'From :from to :to',
        'pin_failed' => 'Attempt :attempt',
        'pin_locked' => 'For :minutes minutes',
    ],
];
