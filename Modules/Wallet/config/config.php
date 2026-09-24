<?php

return [
    'name' => 'Wallet',

    /*
    |--------------------------------------------------------------------------
    | Owner alias map
    |--------------------------------------------------------------------------
    |
    | Read only through Modules\Wallet\Support\OwnerType — deliberately NOT
    | registered via Illuminate\Database\Eloquent\Relations\Relation::morphMap(),
    | which is global per model class and would also rewrite unrelated
    | polymorphic relations on these same models elsewhere in the app (see
    | OwnerType's docblock for the regression that caused this decision).
    |
    | 'platform' and 'system' are pseudo-owners with no Eloquent model — see
    | docs/wallet-structure.md §1.1. Do not add a class entry for them.
    */
    'morph_map' => [
        'user' => \Modules\User\Models\User::class,
        'provider' => \Modules\Provider\Models\Provider::class,
        'admin' => \Modules\Admin\Models\Admin::class,
    ],

    'platform_owner_type' => 'platform',
    'platform_owner_id' => 0,

    /*
    |--------------------------------------------------------------------------
    | PIN pepper
    |--------------------------------------------------------------------------
    |
    | Mixed into every wallet PIN hash (Modules\Wallet\Services\PinService).
    | Must stay out of source control — set WALLET_PIN_PEPPER in .env.
    */
    'pin_pepper' => env('WALLET_PIN_PEPPER'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox gateway
    |--------------------------------------------------------------------------
    |
    | A fake bank so the whole top-up flow can be demoed without real gateway
    | credentials (Services\Gateways\SandboxGateway). ON only for local/testing
    | unless WALLET_SANDBOX_ENABLED says otherwise — it must never be reachable
    | in production, where it would let anyone "pay" without paying.
    */
    'sandbox_enabled' => (bool) env('WALLET_SANDBOX_ENABLED', in_array(env('APP_ENV'), ['local', 'testing'], true)),

    /*
    |--------------------------------------------------------------------------
    | Online payments
    |--------------------------------------------------------------------------
    |
    | A pending payment_transactions row older than this is marked `expired` by
    | the payment:expire-stale command. An expired payment can still be
    | completed by an admin reconcile if the gateway confirms it late.
    */
    'payments' => [
        'expiry_minutes' => (int) env('WALLET_PAYMENT_EXPIRY_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway seed credentials
    |--------------------------------------------------------------------------
    |
    | ONLY used by PaymentMethodSeeder to pre-fill payment_methods.credentials
    | on a fresh install (same idea as AI_GROQ_SEED_API_KEY). At runtime the
    | source of truth is the encrypted payment_methods.credentials column,
    | edited from the admin dashboard — nothing reads these after seeding.
    | A method whose credentials are left empty here is seeded *inactive*.
    */
    'gateway_seed_credentials' => [
        'myfatoorah' => [
            'api_url' => env('MYFATOORAH_API_URL'),
            'api_key' => env('MYFATOORAH_API_KEY'),
        ],
        'arb' => [
            'tranportal_id' => env('ARB_TRANPORTAL_ID'),
            'tranportal_password' => env('ARB_TRANPORTAL_PASSWORD'),
            'tranportal_resource_key' => env('ARB_TRANPORTAL_RESOURCE_KEY'),
            'hosted_url' => env('ARB_TRANPORTAL_HOSTED_URL'),
        ],
        'urpay' => [
            'mode' => env('URPAY_MODE', 'test'),
            'payment_url' => env('URPAY_PAYMENT_URL'),
            'username' => env('URPAY_USERNAME'),
            'password' => env('URPAY_PASSWORD'),
            'client_id' => env('URPAY_CLIENT_ID'),
            'terminal_id' => env('URPAY_TERMINAL_ID'),
            'merchant_wallet_number' => env('URPAY_MERCHANT_WALLET_NUMBER'),
            'merchant_id' => env('URPAY_MERCHANT_ID'),
            'test_consumer_mobile_number' => env('URPAY_TEST_CONSUMER_MOBILE_NUMBER'),
        ],
    ],
];
