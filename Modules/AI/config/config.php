<?php

return [
    'name' => 'AI',

    /*
    |--------------------------------------------------------------------------
    | Connection test timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Supported providers
    |--------------------------------------------------------------------------
    |
    | Metadata used to seed the default rows and to feed the admin settings
    | screen (default base URL, starter model list, docs link, pricing
    | note). Provider catalogs change over time, so the "models" list here
    | is only a starting point shown before the admin has ever run a
    | successful connection test - once they test the connection with a
    | real key, the screen switches to the *live* model list fetched
    | straight from the provider and keeps it in sync from then on.
    |
    */
    'providers' => [
        'openai' => [
            'name' => 'OpenAI (ChatGPT)',
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_model' => 'gpt-4o-mini',
            'models' => [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4.1',
                'gpt-4.1-mini',
                'gpt-4.1-nano',
                'o1',
                'o1-mini',
                'o3',
                'o3-mini',
                'o4-mini',
                'gpt-3.5-turbo',
            ],
            'docs_url' => 'https://platform.openai.com/api-keys',
            'has_free_tier' => false,
            'pricing_note' => 'No standing free tier for API usage - billed per token (new accounts sometimes get a small expiring trial credit).',
        ],
        'anthropic' => [
            'name' => 'Anthropic (Claude)',
            'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'default_model' => 'claude-sonnet-4-5',
            'models' => [
                'claude-opus-4-5',
                'claude-sonnet-4-5',
                'claude-haiku-4-5',
                'claude-opus-4-1',
                'claude-3-7-sonnet-latest',
                'claude-3-5-sonnet-latest',
                'claude-3-5-haiku-latest',
            ],
            'docs_url' => 'https://console.anthropic.com/settings/keys',
            'has_free_tier' => false,
            'pricing_note' => 'No standing free tier for API usage - billed per token (new accounts sometimes get a small expiring trial credit).',
        ],
        'google' => [
            'name' => 'Google (Gemini)',
            'base_url' => env('AI_GOOGLE_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'default_model' => 'gemini-2.5-flash',
            'models' => [
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
                'gemini-2.0-flash',
                'gemini-2.0-flash-lite',
                'gemini-1.5-pro',
                'gemini-1.5-flash',
            ],
            'docs_url' => 'https://aistudio.google.com/apikey',
            'has_free_tier' => true,
            'pricing_note' => 'Google AI Studio keys include a free-of-charge tier with lower rate limits; higher throughput requires enabling billing.',
        ],
        'groq' => [
            'name' => 'Groq',
            'base_url' => env('AI_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'default_model' => 'openai/gpt-oss-120b',
            'models' => [
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'groq/compound',
                'groq/compound-mini',
                'qwen/qwen3.8-27b',
                'allam-2-7b',
                'openai/gpt-oss-safeguard-20b',
                'meta-llama/llama-prompt-guard-2-86m',
                'meta-llama/llama-prompt-guard-2-22m',
                'whisper-large-v3',
                'whisper-large-v3-turbo',
                'canopylabs/orpheus-v1-english',
                'canopylabs/orpheus-arabic-saudi',
            ],
            'docs_url' => 'https://console.groq.com/keys',
            'has_free_tier' => true,
            'pricing_note' => 'Groq offers a generous free-of-charge API tier with rate limits - the cheapest option to experiment with.',
        ],
    ],
];
