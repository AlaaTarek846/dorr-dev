<?php

return [
    'name' => 'Marketing',

    // service_categories.module_name of the service this module powers (dashboard service-availability page).
    'service_module_name' => 'marketing',

    /*
    | Where market research reads the web (docs/marketing_tasks.md D3). `tavily` (free tier, 1,000 credits/month,
    | https://tavily.com) or `none` — research then ends PARTIAL with "no sources" and never makes numbers up.
    */
    'research' => [
        'driver' => env('MARKETING_RESEARCH_DRIVER', 'tavily'),
        'tavily' => [
            'key' => env('TAVILY_API_KEY'),
            'url' => env('TAVILY_API_URL', 'https://api.tavily.com/search'),
            'depth' => env('TAVILY_SEARCH_DEPTH', 'basic'),
            'timeout' => (int) env('TAVILY_TIMEOUT', 20),
        ],
        // Sources a run keeps at most (the app may ask for fewer), and the characters of each one the AI reads.
        'max_sources' => 15,
        'excerpt_chars' => 1500,
        // Under this many sources a run is PARTIAL (coverage too thin to call it complete).
        'min_sources' => 3,
    ],

    /*
    | Ad platforms (phase C, D6: Meta first). With MARKETING_META_SANDBOX=true and a sandbox token + ad account (made in
    | the Meta app dashboard: Marketing API → Tools → Sandbox ad account) "connect" uses that token directly — a sandbox
    | account has no funding source, delivers nothing and spends nothing. Otherwise OAuth with the app id/secret.
    */
    'platforms' => [
        'meta' => [
            'app_id' => env('MARKETING_META_APP_ID'),
            'app_secret' => env('MARKETING_META_APP_SECRET'),
            'version' => env('MARKETING_META_GRAPH_VERSION', 'v21.0'),
            'graph_url' => env('MARKETING_META_GRAPH_URL', 'https://graph.facebook.com'),
            'timeout' => (int) env('MARKETING_META_TIMEOUT', 20),
            'sandbox' => (bool) env('MARKETING_META_SANDBOX', false),
            'sandbox_token' => env('MARKETING_META_SANDBOX_TOKEN'),
            'sandbox_account' => env('MARKETING_META_SANDBOX_ACCOUNT'),
            'webhook_verify_token' => env('MARKETING_META_WEBHOOK_VERIFY_TOKEN'),
        ],
    ],

    // Version tag stored with every financial scenario (MK-A08) — bump it when a formula changes.
    'calculation_version' => 'fin-1',

    // Days a subscription period lasts when the package does not say.
    'default_period_days' => 30,
];
