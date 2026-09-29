<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS logging channel
    |--------------------------------------------------------------------------
    | Channel used for non-sensitive SMS debug lines. Adapters never log
    | credentials; this only carries safe high-level context.
    */
    'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | E.164 recipients
    |--------------------------------------------------------------------------
    | Provider adapter keys that expect recipients in E.164 form. Recipients are
    | normalized for the selected COUNTRY before dispatch (see
    | Modules\Sms\Services\Sms\PhoneNumberNormalizer), so every provider receives
    | a "+<dial_code><national number>" value regardless.
    */
    'e164_providers' => [
        'twilio',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default sender type
    |--------------------------------------------------------------------------
    | The default sender_type used on new accounts when none is chosen.
    */
    'default_sender_type' => env('SMS_DEFAULT_SENDER_TYPE', 'number'),

    /*
    |--------------------------------------------------------------------------
    | Send test SMS defaults
    |--------------------------------------------------------------------------
    */
    'test' => [
        // If a provider has a sandbox/testing environment, send-test uses it.
        'prefer_sandbox' => true,
        // When no sandbox exists, the frontend must warn before a live test SMS
        // is sent (the response is flagged with test_environment = false).
        'require_live_warning' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    | Whether SMS is enabled at all. SmsAvailabilityService is the authority for
    | which specific account is usable.
    */
    'enabled' => env('SMS_ENABLED', true),
];
