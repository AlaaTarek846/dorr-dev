<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exchange rate provider
    |--------------------------------------------------------------------------
    |
    | Supported: open_er_api
    |
    */

    'provider' => env('EXCHANGE_RATE_PROVIDER', 'open_er_api'),

    /*
    |--------------------------------------------------------------------------
    | Base currency code
    |--------------------------------------------------------------------------
    |
    | When null, the currency marked as default in the database is used.
    |
    */

    'base_currency' => env('EXCHANGE_RATE_BASE'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => (int) env('EXCHANGE_RATE_CACHE_TTL', 3600),

    'open_er_api_url' => env('EXCHANGE_RATE_API_URL', 'https://open.er-api.com/v6/latest'),

];
