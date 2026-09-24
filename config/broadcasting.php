<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    | Default: "log" — change to "pusher" (Soketi / Pusher) when notifications
    |           are enabled in production.
    */
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        /*
        |----------------------------------------------------------------------
        | Pusher / Soketi
        |----------------------------------------------------------------------
        | Used by GeneralNotification and BroadcastOnlyNotification.
        | For local dev with Soketi:
        |   PUSHER_HOST=127.0.0.1
        |   PUSHER_PORT=6001
        |   PUSHER_SCHEME=http
        |   BROADCAST_CONNECTION=pusher
        */
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options:
                // 'curl' => [CURLOPT_SSL_VERIFYPEER => false], // only for local http
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Reverb (Laravel first-party WebSocket server — alternative to Soketi)
        |----------------------------------------------------------------------
        */
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '0.0.0.0'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
