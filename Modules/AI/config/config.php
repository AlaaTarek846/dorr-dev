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
    | User-facing chat
    |--------------------------------------------------------------------------
    */
    'chat' => [
        // Anthropic requires max_tokens on every request; the others treat
        // it as an optional cap, so this also acts as their sane default.
        'default_max_tokens' => (int) env('AI_CHAT_DEFAULT_MAX_TOKENS', 1024),

        // How many previous messages (both roles) to send back as context.
        // Keeps token usage and per-request latency bounded on long chats.
        'history_limit' => (int) env('AI_CHAT_HISTORY_LIMIT', 30),

        'system_prompt' => env(
            'AI_CHAT_SYSTEM_PROMPT',
            'You are "DORR AI" (مساعد دور الذكي), the official AI assistant built into the DORR '
            .'multi-service platform. You help customers discover and use DORR\'s services - rides, '
            .'delivery, home services, bookings, and everything else on the platform. '
            .'Always speak as DORR\'s own assistant: never reveal, confirm, or discuss which underlying '
            .'AI provider or model powers you, even if asked directly - politely decline and redirect '
            .'to how you can help instead. '
            .'Be concise, warm, and professional. Always reply in the same language the user wrote in '
            .'(Arabic or English), matching their tone. If the user attaches a file, acknowledge it by '
            .'name and ask what they would like you to do with it if that is not already clear.',
        ),

        // Shown in the UI instead of the real provider name, so the
        // assistant reads as DORR's own product rather than a wrapper
        // around a third-party API.
        'brand_name' => env('AI_CHAT_BRAND_NAME', 'DORR AI'),

        // v2.0 requirements doc S15.3: push the final assistant reply over
        // Reverb (Modules\AI\Events\AiMessageBroadcast) in addition to the
        // normal synchronous HTTP response. Off by default - turning it on
        // without a configured/running Reverb server is harmless (the
        // broadcast call is wrapped in a try/catch), but there is no
        // reason to pay the dispatch cost unless config/broadcasting.php
        // is actually pointed at a real Reverb connection.
        'broadcast_enabled' => (bool) env('AI_CHAT_BROADCAST_ENABLED', false),

        /*
        |----------------------------------------------------------------
        | Verification Engine (v2.0 requirements doc, section 6)
        |----------------------------------------------------------------
        |
        | Every draft reply is fact-checked by a second AI call before it
        | reaches the user. If confidence comes back too low, the draft is
        | regenerated (bounded by max_attempts) with the verifier's issues
        | fed back in; if it still doesn't clear the bar, the system
        | abstains (asks for clarification) instead of answering with an
        | overconfident guess. This roughly doubles cost/latency per
        | message (an extra model call per attempt), hence the toggle.
        |
        */
        'verification' => [
            'enabled' => (bool) env('AI_CHAT_VERIFICATION_ENABLED', true),

            // confidence_score >= pass_threshold -> answer goes out as-is.
            'pass_threshold' => (float) env('AI_CHAT_VERIFICATION_PASS_THRESHOLD', 0.6),

            // confidence_score < abstain_threshold after all attempts ->
            // the system abstains rather than showing the draft at all.
            'abstain_threshold' => (float) env('AI_CHAT_VERIFICATION_ABSTAIN_THRESHOLD', 0.35),

            // Total generation attempts allowed (the first draft counts as
            // attempt 1), so 2 means at most one corrective regeneration.
            'max_attempts' => (int) env('AI_CHAT_VERIFICATION_MAX_ATTEMPTS', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge base / RAG (v2.0 requirements doc, section 5)
    |--------------------------------------------------------------------------
    */
    'knowledge' => [
        'enabled' => (bool) env('AI_KNOWLEDGE_ENABLED', true),

        // OpenAI embeddings model used when an OpenAI provider is
        // configured (currently the only connector with real embeddings
        // support - see OpenAiConnector::embed()).
        'embedding_model' => env('AI_KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-small'),

        // Target chunk size / overlap in characters for the ingestion
        // pipeline's chunking step (section 5.2).
        'chunk_size' => (int) env('AI_KNOWLEDGE_CHUNK_SIZE', 1000),
        'chunk_overlap' => (int) env('AI_KNOWLEDGE_CHUNK_OVERLAP', 150),

        // How many top-scoring chunks to inject as evidence per message,
        // and the minimum hybrid score below which a chunk is considered
        // noise rather than evidence.
        'retrieval_limit' => (int) env('AI_KNOWLEDGE_RETRIEVAL_LIMIT', 5),
        'min_relevance_score' => (float) env('AI_KNOWLEDGE_MIN_RELEVANCE_SCORE', 0.15),

        // Upper bound on how many searchable chunks a single retrieval
        // pass will load and score, so a large knowledge base can't turn
        // every chat message into an unbounded table scan.
        'max_chunks_scanned' => (int) env('AI_KNOWLEDGE_MAX_CHUNKS_SCANNED', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Code sandbox (v2.0 requirements doc, section 10.3 / 17.5)
    |--------------------------------------------------------------------------
    |
    | Real, isolated code execution for the "code" domain pipeline - never
    | a cosmetic/simulated result. The docker driver runs with no network,
    | a fresh throwaway container, and bounded CPU/RAM/pids/time, then is
    | torn down (--rm). If docker isn't reachable, AiSandboxRunner reports
    | status=unavailable rather than pretending the code ran.
    */
    'sandbox' => [
        'enabled' => (bool) env('AI_SANDBOX_ENABLED', true),
        'driver' => env('AI_SANDBOX_DRIVER', 'docker'),
        'timeout_seconds' => (int) env('AI_SANDBOX_TIMEOUT_SECONDS', 10),
        'memory_limit' => env('AI_SANDBOX_MEMORY_LIMIT', '256m'),
        'cpus' => env('AI_SANDBOX_CPUS', '0.5'),
        'pids_limit' => (int) env('AI_SANDBOX_PIDS_LIMIT', 64),
        'max_correction_attempts' => (int) env('AI_SANDBOX_MAX_CORRECTION_ATTEMPTS', 2),

        // language key => [docker image, entrypoint command with {file} placeholder]
        'languages' => [
            'php' => [
                'image' => 'php:8.3-cli-alpine',
                'command' => ['php', '{file}'],
                'extension' => 'php',
            ],
            'node' => [
                'image' => 'node:20-alpine',
                'command' => ['node', '{file}'],
                'extension' => 'js',
            ],
            'python' => [
                'image' => 'python:3.12-alpine',
                'command' => ['python3', '{file}'],
                'extension' => 'py',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain pipelines (v2.0 requirements doc, sections 7-14)
    |--------------------------------------------------------------------------
    */
    'domains' => [
        'enabled' => (bool) env('AI_DOMAIN_PIPELINES_ENABLED', true),
    ],

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
    /*
    |--------------------------------------------------------------------------
    | Benchmark DORR (v2.0 requirements doc, section 19)
    |--------------------------------------------------------------------------
    |
    | Admin-triggered batch runs over the ai_benchmark_cases bank, scored
    | by AiBenchmarkRunner. See that class's docblock for exactly which
    | v2.0-doc metrics are computed for real here versus intentionally
    | left as a disclosed follow-up (groundedness beyond citation
    | presence, hallucination rate, per-token cost).
    |
    */
    'benchmark' => [
        // A run this large or larger triggers a UI warning (not a hard
        // stop) that the sample size may be too small to certify a
        // headline pass-rate figure per S19.5 - it never blocks the run.
        'min_recommended_sample_size' => (int) env('AI_BENCHMARK_MIN_SAMPLE_SIZE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker (v2.0 doc S15.4/S20.3)
    |--------------------------------------------------------------------------
    |
    | After `failure_threshold` consecutive failed calls to a provider,
    | the breaker opens and that provider is skipped (failed fast, no
    | network call) for `open_seconds` before being tried again. State is
    | tracked as ai_provider_health rows (check_type=functional) rather
    | than a new table, since that table already exists to record exactly
    | this kind of per-provider health signal.
    |
    */
    'circuit_breaker' => [
        'enabled' => (bool) env('AI_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => (int) env('AI_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),
        'open_seconds' => (int) env('AI_CIRCUIT_BREAKER_OPEN_SECONDS', 60),
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
