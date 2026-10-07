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
    | AI website builder
    |--------------------------------------------------------------------------
    | Generated sites are served with a CSP sandbox. If 'domain' is set they are
    | served from that dedicated host (recommended in production), otherwise
    | under /ai-sites on the app host.
    */
    'sites' => [
        'enabled' => (bool) env('AI_SITES_ENABLED', true),
        'domain' => env('AI_SITES_DOMAIN'),
        'max_output_tokens' => (int) env('AI_SITES_MAX_OUTPUT_TOKENS', 16000),
        'request_timeout' => (int) env('AI_SITES_REQUEST_TIMEOUT', 600),
        // A build/edit still pending/processing after this many minutes is failed automatically.
        'stale_after_minutes' => (int) env('AI_SITES_STALE_AFTER_MINUTES', 30),
        'max_files' => (int) env('AI_SITES_MAX_FILES', 30),
        'max_file_bytes' => (int) env('AI_SITES_MAX_FILE_BYTES', 600000),
        'max_total_bytes' => (int) env('AI_SITES_MAX_TOTAL_BYTES', 3000000),
        'edit_context_bytes' => (int) env('AI_SITES_EDIT_CONTEXT_BYTES', 150000),
        'max_versions' => (int) env('AI_SITES_MAX_VERSIONS', 30),

        // Paid hosting of a finished site on its own address (sub-domain of
        // AI_SITES_HOSTING_DOMAIN, or /sites/{name} on the app host when empty).
        'hosting' => [
            'enabled' => (bool) env('AI_SITES_HOSTING_ENABLED', true),
            'domain' => env('AI_SITES_HOSTING_DOMAIN'),
            'path_prefix' => trim((string) env('AI_SITES_HOSTING_PATH_PREFIX', 'sites'), '/'),
            'scheme' => env('AI_SITES_HOSTING_SCHEME', 'https'),
            'grace_days' => (int) env('AI_SITES_HOSTING_GRACE_DAYS', 3),
            'delete_after_days' => (int) env('AI_SITES_HOSTING_DELETE_AFTER_DAYS', 30),
            'cache_seconds' => (int) env('AI_SITES_HOSTING_CACHE_SECONDS', 120),
            'name_min' => 3,
            'name_max' => 40,
            // Extra reserved names, comma separated, on top of the built-in list.
            'reserved' => array_filter(array_map('trim', explode(',', (string) env('AI_SITES_HOSTING_RESERVED', '')))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Video generation (chat)
    |--------------------------------------------------------------------------
    | Per-plan limits (videos a day, max seconds) live on ai_plans; these are
    | only the technical knobs. Needs a running queue worker.
    */
    'video' => [
        'enabled' => (bool) env('AI_VIDEO_ENABLED', true),
        'size' => env('AI_VIDEO_SIZE', '1280x720'),
        'aspect_ratio' => env('AI_VIDEO_ASPECT_RATIO', '16:9'),
        'openai_enabled' => (bool) env('AI_OPENAI_VIDEO_ENABLED', false),
        'default_seconds' => (int) env('AI_VIDEO_DEFAULT_SECONDS', 4),
        'poll_interval' => (int) env('AI_VIDEO_POLL_INTERVAL', 15),
        'timeout' => (int) env('AI_VIDEO_TIMEOUT', 900),
        'download_timeout' => (int) env('AI_VIDEO_DOWNLOAD_TIMEOUT', 180),
    ],

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

        // Max original image size this will inline as base64 to a
        // vision-capable model. Bigger than this, the image is described
        // as a plain text note instead (still uploaded/stored either way).
        'multimodal_max_image_bytes' => (int) env('AI_CHAT_MULTIMODAL_MAX_IMAGE_BYTES', 8 * 1024 * 1024),

        // Max characters of extracted document text (PDF/DOCX/plain text)
        // to inline into the prompt for a document_analysis turn. Longer
        // documents are truncated with a note rather than sent in full,
        // to keep token usage and latency bounded.
        'multimodal_max_document_chars' => (int) env('AI_CHAT_MULTIMODAL_MAX_DOCUMENT_CHARS', 6000),

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
            .'name and ask what they would like you to do with it if that is not already clear. '
            .'Only bring up DORR\'s services when they are actually relevant to what the user just '
            .'asked - never tack on a generic "how else can I help you with DORR\'s services" line '
            .'to an unrelated answer or to a refusal; it reads as a canned sales pitch and makes the '
            .'reply feel disjointed. If a request is outside what you can do, say so plainly in one '
            .'sentence and stop there, or offer a genuinely relevant next step only when one exists.',
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
    | Universal AI File Engine (master plan - Phase 1)
    |--------------------------------------------------------------------------
    |
    | Limits for AiFileEngine/AiFileProcessorManager - configurable per
    | master plan #41, never hard-coded in a processor.
    */
    'files' => [
        // A spreadsheet (XLSX/XLS/CSV) sheet is read row-by-row via
        // ExcelFileProcessor rather than loaded whole into one array
        // (master plan #14), but is still bounded by this many data rows
        // per sheet so one huge spreadsheet can't block a queue worker
        // indefinitely - extra rows are noted as truncated, not silently
        // dropped.
        'spreadsheet_max_rows_per_sheet' => (int) env('AI_FILES_SPREADSHEET_MAX_ROWS_PER_SHEET', 2000),

        // A file whose checksum already exists for the same owner is
        // reused as-is instead of being re-processed (master plan #42).
        'dedupe_by_checksum' => (bool) env('AI_FILES_DEDUPE_BY_CHECKSUM', true),

        // Below this many characters, an uploaded file's extracted text
        // is inlined directly into the chat prompt (same as today); at or
        // above it, it becomes eligible for chunking/indexing instead so
        // AiKnowledgeRetriever can pull only the relevant parts (ties
        // into the knowledge base pipeline built for Phase 8/9).
        'chunk_indexing_threshold_chars' => (int) env('AI_FILES_CHUNK_INDEXING_THRESHOLD_CHARS', 6000),

        // Acceptance criteria doc S8/S25: configurable upload limits -
        // never hard-coded in a controller or FormRequest.
        'max_size_bytes' => (int) env('AI_FILES_MAX_SIZE_BYTES', 26214400), // 25 MB

        // Phase 12 (doc S7/S28): a per-owner cap on total stored files
        // and total storage bytes - abuse protection independent of the
        // per-request size/type checks below. 0/null disables that
        // dimension (checked in AiFileEngine::storeUploadedFile(), before
        // the file is even written to disk).
        'max_files_per_owner' => (int) env('AI_FILES_MAX_FILES_PER_OWNER', 200),
        'max_storage_bytes_per_owner' => (int) env('AI_FILES_MAX_STORAGE_BYTES_PER_OWNER', 1073741824), // 1 GB

        // Security allowlist (doc S9/S25): checked in AiFileEngine before
        // a file is ever stored, independent of whether a real processor
        // exists yet for that type (processor coverage can lag behind
        // what is safe to simply accept and hold - doc S17).
        'allowed_mime_types' => array_filter(array_map('trim', explode(',', (string) env(
            'AI_FILES_ALLOWED_MIME_TYPES',
            'application/pdf,application/msword,'
            .'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
            .'application/vnd.ms-excel,'
            .'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
            .'text/csv,text/tab-separated-values,text/plain,text/markdown,text/html,'
            .'application/json,text/json,application/xml,text/xml,'
            .'application/vnd.openxmlformats-officedocument.presentationml.presentation,'
            .'application/vnd.ms-powerpoint,'
            .'image/png,image/jpeg,image/webp,image/gif,image/bmp,image/tiff,image/svg+xml,'
            .'audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/wave,audio/mp4,audio/x-m4a,audio/m4a,'
            .'audio/aac,audio/ogg,audio/opus,audio/flac,audio/x-flac,audio/webm,video/webm,'
            .'video/mp4,video/quicktime,video/x-msvideo'
        )))),

        // Phase 2 (Document Processing) limits - never hard-coded inside
        // a processor (doc S8/S25).
        'json_max_depth' => (int) env('AI_FILES_JSON_MAX_DEPTH', 64),
        'json_max_leaf_blocks' => (int) env('AI_FILES_JSON_MAX_LEAF_BLOCKS', 500),
        'xml_max_nodes' => (int) env('AI_FILES_XML_MAX_NODES', 2000),

        // Phase 3 (Spreadsheet Processing) - CSV/TSV delimiter detection
        // samples only this many leading non-empty lines (doc S18); a
        // bigger sample costs nothing on a normal file but is capped so
        // a pathological one-huge-line file can't stall detection.
        'csv_delimiter_sample_lines' => (int) env('AI_FILES_CSV_DELIMITER_SAMPLE_LINES', 15),

        // Phase 5 (Image Processing) - decompression-bomb / excessive-
        // memory protection (doc S6/S9): checked from getimagesize()'s
        // dimensions BEFORE any pixel data is decoded, so a tiny file
        // claiming huge dimensions is rejected cheaply rather than by
        // actually trying to decode it first.
        'image_max_width' => (int) env('AI_FILES_IMAGE_MAX_WIDTH', 8000),
        'image_max_height' => (int) env('AI_FILES_IMAGE_MAX_HEIGHT', 8000),
        'image_max_pixels' => (int) env('AI_FILES_IMAGE_MAX_PIXELS', 40000000),

        // Doc S10: an animated GIF/WEBP with an absurd frame count is
        // rejected rather than letting the preview step try to deal
        // with all of them.
        'image_max_animation_frames' => (int) env('AI_FILES_IMAGE_MAX_ANIMATION_FRAMES', 500),

        // Doc S8: preview/thumbnail target sizes (longest side, aspect
        // ratio preserved) - never hardcoded in the processor.
        'image_thumbnail_max_dimension' => (int) env('AI_FILES_IMAGE_THUMBNAIL_MAX_DIMENSION', 320),
        'image_preview_max_dimension' => (int) env('AI_FILES_IMAGE_PREVIEW_MAX_DIMENSION', 1280),

        // Phase 6 (Audio Processing) - external binary paths, never
        // hardcoded (doc S5/S28/architectural rule 10). Default to a
        // bare binary name resolved via PATH rather than an assumed
        // absolute path - both are genuinely reachable that way in this
        // project's dev environment (verified), but neither is assumed
        // to exist on every deployment target; AudioFileProcessor
        // detects availability at runtime and degrades to
        // AUDIO_PROCESSOR_UNAVAILABLE rather than crashing.
        'audio_ffmpeg_path' => (string) env('AI_AUDIO_FFMPEG_PATH', 'ffmpeg'),
        'audio_ffprobe_path' => (string) env('AI_AUDIO_FFPROBE_PATH', 'ffprobe'),

        // Doc S7/S17: ffprobe/ffmpeg are both run with a hard wall-clock
        // timeout (Symfony Process) so a hung/adversarial file can never
        // hang a queue worker.
        'audio_probe_timeout_seconds' => (int) env('AI_AUDIO_PROBE_TIMEOUT_SECONDS', 15),

        // Doc S7/S17: resource limits, checked from ffprobe's real
        // decoded metadata - never trusted from the client, filename, or
        // an unvalidated tag.
        'audio_max_duration_seconds' => (int) env('AI_AUDIO_MAX_DURATION_SECONDS', 3600),
        'audio_max_sample_rate' => (int) env('AI_AUDIO_MAX_SAMPLE_RATE', 192000),
        'audio_max_channels' => (int) env('AI_AUDIO_MAX_CHANNELS', 8),

        // Doc S12: waveform generation is optional and can be turned off
        // entirely; its source decode is capped at this many seconds
        // REGARDLESS of the file's real duration, bounding memory use on
        // even a multi-hour recording, and the output is capped at this
        // many bars - never the full-resolution sample data.
        'audio_waveform_enabled' => (bool) env('AI_AUDIO_WAVEFORM_ENABLED', true),
        'audio_waveform_bars' => (int) env('AI_AUDIO_WAVEFORM_BARS', 100),
        'audio_waveform_max_source_seconds' => (int) env('AI_AUDIO_WAVEFORM_MAX_SOURCE_SECONDS', 120),
        'audio_waveform_timeout_seconds' => (int) env('AI_AUDIO_WAVEFORM_TIMEOUT_SECONDS', 20),

        // Defense-in-depth reject list (doc S9: "executable uploads"),
        // checked by filename extension - independent of and in addition
        // to the real finfo MIME detection AiFileEngine already does.
        'blocked_extensions' => array_filter(array_map('trim', explode(',', (string) env(
            'AI_FILES_BLOCKED_EXTENSIONS',
            'exe,bat,cmd,com,scr,msi,jar,app,dll,so,sh,bash,ps1,'
            .'php,php3,php4,php5,php7,phtml,pht,cgi,asp,aspx,jsp,vbs,wsf,hta'
        )))),

        // Phase 7 (Video Processing) - same runtime-detected, configurable-
        // path external-binary approach as Phase 6's audio config
        // (doc S28/architectural rule: never hardcode a system path).
        // Deliberately separate env vars from AI_AUDIO_FFMPEG_PATH/
        // AI_AUDIO_FFPROBE_PATH, even though they resolve to the same
        // binary on this project's dev environment, so a deployment
        // that genuinely needs two different builds (e.g. a video-
        // capable ffmpeg build on one host, audio-only on another)
        // is not forced to share one path.
        'video_ffmpeg_path' => (string) env('AI_VIDEO_FFMPEG_PATH', env('AI_AUDIO_FFMPEG_PATH', 'ffmpeg')),
        'video_ffprobe_path' => (string) env('AI_VIDEO_FFPROBE_PATH', env('AI_AUDIO_FFPROBE_PATH', 'ffprobe')),
        'video_probe_timeout_seconds' => (int) env('AI_VIDEO_PROBE_TIMEOUT_SECONDS', 20),

        // Doc S23: resource limits, checked from ffprobe's real decoded
        // metadata - never trusted from the client/filename. Overall
        // file size is already bounded by the generic
        // `files.max_size_bytes` limit above (doc S23: "do not
        // duplicate quota logic") before a video ever reaches this
        // processor at all.
        'video_max_duration_seconds' => (int) env('AI_VIDEO_MAX_DURATION_SECONDS', 3600),
        'video_max_width' => (int) env('AI_VIDEO_MAX_WIDTH', 7680),
        'video_max_height' => (int) env('AI_VIDEO_MAX_HEIGHT', 4320),

        // Doc S18/S19: the always-on single poster/thumbnail frame -
        // bounded dimension, never the source resolution.
        'video_poster_max_dimension' => (int) env('AI_VIDEO_POSTER_MAX_DIMENSION', 480),
        'video_poster_timeout_seconds' => (int) env('AI_VIDEO_POSTER_TIMEOUT_SECONDS', 20),

        // Doc S17: bounds for the SEPARATE, on-demand VideoFrameExtractor
        // service (never invoked automatically from VideoFileProcessor
        // itself - see its own docblock).
        'video_frame_max_count' => (int) env('AI_VIDEO_FRAME_MAX_COUNT', 12),
        'video_frame_max_dimension' => (int) env('AI_VIDEO_FRAME_MAX_DIMENSION', 480),
        'video_frame_timeout_seconds' => (int) env('AI_VIDEO_FRAME_TIMEOUT_SECONDS', 20),

        // Doc S12: bounds for the SEPARATE, on-demand audio-extraction-
        // to-temp-file capability (AnalyzesVideoData::extractAudioToTempFile()
        // - also never invoked automatically).
        'video_audio_extraction_max_seconds' => (int) env('AI_VIDEO_AUDIO_EXTRACTION_MAX_SECONDS', 3600),
        'video_audio_extraction_timeout_seconds' => (int) env('AI_VIDEO_AUDIO_EXTRACTION_TIMEOUT_SECONDS', 60),

        // Which Laravel filesystem disk new uploads are written to.
        // Kept as one config value (not scattered literals) so moving to
        // an S3-compatible disk later (doc S10) only means changing this.
        'default_disk' => (string) env('AI_FILES_DEFAULT_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Engine chunking (Phase 8)
    |--------------------------------------------------------------------------
    |
    | Converts a file's already-normalized content (produced by the file
    | processors above) into searchable AiFileChunk rows. Defaults chosen
    | to match the sizes AiTextChunker/AiKnowledgeIngestionService already
    | use for the admin Knowledge Base, so chunk sizes feel consistent
    | across both features rather than arbitrarily different.
    */
    'chunking' => [
        'enabled' => (bool) env('AI_CHUNKING_ENABLED', true),
        'storage_disk' => env('AI_CHUNKING_STORAGE_DISK', env('AI_FILES_DEFAULT_DISK', 'public')),
        'default_strategy' => env('AI_CHUNKING_DEFAULT_STRATEGY', 'document'),
        'batch_size' => (int) env('AI_CHUNKING_BATCH_SIZE', 200),

        'document' => [
            'max_characters' => (int) env('AI_CHUNKING_DOCUMENT_MAX_CHARACTERS', 2000),
            'max_tokens' => (int) env('AI_CHUNKING_DOCUMENT_MAX_TOKENS', 500),
            'min_characters' => (int) env('AI_CHUNKING_DOCUMENT_MIN_CHARACTERS', 20),
            'overlap_blocks' => (int) env('AI_CHUNKING_DOCUMENT_OVERLAP_BLOCKS', 1),
            'table_rows_per_chunk' => (int) env('AI_CHUNKING_DOCUMENT_TABLE_ROWS_PER_CHUNK', 50),
        ],

        'structured' => [
            'max_characters' => (int) env('AI_CHUNKING_STRUCTURED_MAX_CHARACTERS', 2000),
            'group_by_depth' => (int) env('AI_CHUNKING_STRUCTURED_GROUP_BY_DEPTH', 1),
        ],

        'spreadsheet' => [
            'rows_per_chunk' => (int) env('AI_CHUNKING_SPREADSHEET_ROWS_PER_CHUNK', 100),
        ],

        'presentation' => [
            'slides_per_chunk' => (int) env('AI_CHUNKING_PRESENTATION_SLIDES_PER_CHUNK', 1),
            'max_characters' => (int) env('AI_CHUNKING_PRESENTATION_MAX_CHARACTERS', 1500),
        ],

        'transcript' => [
            'max_seconds' => (float) env('AI_CHUNKING_TRANSCRIPT_MAX_SECONDS', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Engine indexing (Phase 8)
    |--------------------------------------------------------------------------
    |
    | `auto_embed` defaults to false deliberately: File Engine chunking
    | runs automatically on every processed chat attachment, so calling
    | an embedding API per chunk by default would be a real, uncontrolled
    | cost - unlike the admin Knowledge Base's own deliberate eager-embed
    | behavior (AiKnowledgeIngestionService), which only runs when an
    | admin explicitly submits a source. Phase 9 decides whether/when to
    | turn this on.
    */
    'indexing' => [
        'enabled' => (bool) env('AI_INDEXING_ENABLED', true),
        'batch_size' => (int) env('AI_INDEXING_BATCH_SIZE', 200),
        'queue' => env('AI_INDEXING_QUEUE', 'default'),
        'retry_attempts' => (int) env('AI_INDEXING_RETRY_ATTEMPTS', 3),
        'backend' => env('AI_INDEXING_BACKEND', 'database'),
        'auto_embed' => (bool) env('AI_INDEXING_AUTO_EMBED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | File retrieval / RAG (Phase 9)
    |--------------------------------------------------------------------------
    |
    | Retrieval over the user's OWN uploaded files (ai_files/
    | ai_file_chunks) - entirely separate from `knowledge.*` above, which
    | governs the admin-curated Knowledge Base. Disabled entirely via
    | `enabled`; `allow_global_file_scope` stays false by default since
    | no customer-facing UI exists yet to let a user knowingly search
    | across every file they've ever uploaded (doc S15.D) - leaving it
    | off avoids silently surfacing an old, possibly-forgotten file's
    | content into an unrelated new conversation.
    */
    'retrieval' => [
        'enabled' => (bool) env('AI_RETRIEVAL_ENABLED', true),
        'default_mode' => env('AI_RETRIEVAL_DEFAULT_MODE', 'hybrid'),
        'allow_global_file_scope' => (bool) env('AI_RETRIEVAL_ALLOW_GLOBAL_FILE_SCOPE', false),

        // retrieve this many candidates, score/rank/dedupe them, then
        // keep only the top this-many - doc S16, never unbounded.
        'candidate_k' => (int) env('AI_RETRIEVAL_CANDIDATE_K', 30),
        'top_k' => (int) env('AI_RETRIEVAL_TOP_K', 8),

        'min_relevance_score' => (float) env('AI_RETRIEVAL_MIN_RELEVANCE_SCORE', 0.1),

        'context' => [
            'max_chunks' => (int) env('AI_RETRIEVAL_CONTEXT_MAX_CHUNKS', 8),
            'max_characters' => (int) env('AI_RETRIEVAL_CONTEXT_MAX_CHARACTERS', 6000),
            'max_tokens' => (int) env('AI_RETRIEVAL_CONTEXT_MAX_TOKENS', 1500),
        ],

        // Doc S11/S12's documented hybrid formula: combined =
        // (semantic_score * semantic_weight) + (keyword_score *
        // keyword_weight), falling back to keyword-only when no
        // semantic score is available for a given chunk. Both weights
        // are expected to sum to roughly 1.0 but this is not enforced -
        // an admin deliberately over/under-weighting one signal is a
        // valid tuning choice.
        'hybrid' => [
            'keyword_weight' => (float) env('AI_RETRIEVAL_HYBRID_KEYWORD_WEIGHT', 0.4),
            'semantic_weight' => (float) env('AI_RETRIEVAL_HYBRID_SEMANTIC_WEIGHT', 0.6),
        ],

        // Phase 10 (doc S42): multi-file conversations. max_files bounds
        // both an explicit file_ids[] request payload
        // (AiConversationFileScope::resolveExplicitFileIds()) and the
        // request validation layer (AiSendMessageRequest) - never
        // unlimited.
        'multi_file' => [
            'enabled' => (bool) env('AI_RETRIEVAL_MULTI_FILE_ENABLED', true),
            'max_files' => (int) env('AI_RETRIEVAL_MULTI_FILE_MAX_FILES', 10),

            // Doc S7/S24: caps how many of the final top_k slots a
            // single file may occupy once more than one distinct file
            // is present among the ranked candidates, so one highly
            // relevant file cannot crowd out every other relevant file.
            // Disabled (or a single-file candidate set) falls back to
            // pure global ranking, unchanged from Phase 9.
            'diversity' => [
                'enabled' => (bool) env('AI_RETRIEVAL_DIVERSITY_ENABLED', true),
                'max_chunks_per_file' => (int) env('AI_RETRIEVAL_DIVERSITY_MAX_CHUNKS_PER_FILE', 4),
            ],
        ],
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
    | Dynamic Model Registry sync (OpenAI model management rebuild)
    |--------------------------------------------------------------------------
    |
    | How often AIServiceProvider's scheduled `ai:sync-models` run fires -
    | deliberately configurable rather than a fixed guess, since a
    | fast-moving provider catalog may need syncing more often than a
    | stable one. AIServiceProvider::modelSyncCronExpression() turns this
    | into an actual cron expression (>=24 and a multiple of 24 -> once
    | daily at 03:00; otherwise every N hours).
    |
    */
    'model_sync' => [
        'interval_hours' => (int) env('AI_MODEL_SYNC_INTERVAL_HOURS', 24),
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

    /*
    |--------------------------------------------------------------------------
    | Internal tools / function calling (master spec section 12/48-49)
    |--------------------------------------------------------------------------
    |
    | AiToolResolver's trigger map - which tool name to consider when the
    | user's message matches these Arabic/English phrases. Same pattern as
    | AiRequiredCapabilityResolver's keyword map: a deterministic, easy-to
    | -extend trigger list rather than a black-box classifier. Each tool
    | class here must implement Modules\AI\Contracts\AiToolInterface and
    | is resolved through the container (AiToolRegistry), so a tool with
    | its own constructor dependencies (like AiUsageStatusTool's
    | AiChatUsageGuard) is wired automatically.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Smart intent router ("step 2" after the static dictionary)
    |--------------------------------------------------------------------------
    |
    | When AiChatLexicon does not recognise what a message asks for, the
    | DEFAULT provider's default model is asked once to classify it, and a
    | confident answer is remembered in ai_learned_intents so the same kind
    | of message is understood for free afterwards. See AiIntentRouterService.
    |
    */
    'intent_router' => [
        'enabled' => (bool) env('AI_INTENT_ROUTER_ENABLED', true),

        // null = the default provider's own model (recommended).
        'model' => env('AI_INTENT_ROUTER_MODEL'),

        'min_confidence' => (float) env('AI_INTENT_ROUTER_MIN_CONFIDENCE', 0.7),
        'learn_min_confidence' => (float) env('AI_INTENT_ROUTER_LEARN_MIN_CONFIDENCE', 0.9),
        // A trigger phrase must be confirmed this many times (same intent, never contradicted) before it is active.
        'learn_min_confirmations' => (int) env('AI_INTENT_ROUTER_LEARN_MIN_CONFIRMATIONS', 2),

        'min_message_chars' => 6,
        'max_message_chars' => 600,

        'max_calls_per_minute' => (int) env('AI_INTENT_ROUTER_MAX_CALLS_PER_MINUTE', 20),
        'failure_threshold' => 3,
        'failure_cooldown_seconds' => 300,
        'negative_cache_minutes' => 1440,

        'max_learned_rows' => 5000,
        'active_cache_seconds' => 300,
    ],

    'tools' => [
        'enabled' => (bool) env('AI_TOOLS_ENABLED', true),

        'registry' => [
            \Modules\AI\Services\Tools\AiUsageStatusTool::class,
        ],

        'triggers' => [
            'usage_status' => [
                'كام رسالة باقيلي', 'باقيلي كام', 'استخدامي', 'حد الاستخدام', 'وصلت للحد',
                'الاشتراك بتاعي', 'الباقة بتاعتي', 'تجربتي المجانية', 'كام دقيقة باقيلي',
                'how many messages', 'messages left', 'my usage', 'usage limit', 'my plan',
                'my subscription', 'free trial', 'how much time left', 'am i blocked',
            ],
        ],
    ],
];
