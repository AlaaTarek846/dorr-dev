<?php

use Modules\Provider\Models\Provider;
use Modules\User\Models\User;

return [
    'name' => 'Chat',

    /*
    |--------------------------------------------------------------------------
    | Participant alias map
    |--------------------------------------------------------------------------
    |
    | Who can take part in a conversation, stored as a short alias in every
    | *_type column (never the class name). Read only through
    | Modules\Chat\Support\ParticipantType — deliberately NOT
    | Relation::morphMap(), for the same reason as the wallet (see
    | Modules\Wallet\Support\OwnerType's docblock).
    |
    | `enabled` is who may actually chat today (docs/chat-plan.md §9.1): users
    | only for now. Providers — and drivers, once that module exists — are
    | switched on here later, with no schema change.
    */
    'participants' => [
        'user' => User::class,
        'provider' => Provider::class,
    ],

    'enabled_participants' => ['user'],

    /*
    |--------------------------------------------------------------------------
    | Business tools
    |--------------------------------------------------------------------------
    |
    | Who gets quick replies ("/"), opening hours, and the welcome / away
    | messages. Everyone for now (only users chat today, and a small shop
    | owner is a user too); narrow it to ['provider'] once providers chat.
    */
    'business_participants' => ['user', 'provider'],

    /*
    |--------------------------------------------------------------------------
    | Calls (LiveKit)
    |--------------------------------------------------------------------------
    |
    | LiveKit Cloud today, our own LiveKit server later — only these values
    | change (docs/chat-plan.md §10.2). The secret signs the per-call access
    | tokens and never leaves the server.
    */
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'token_ttl_seconds' => (int) env('LIVEKIT_TOKEN_TTL_SECONDS', 6 * 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Call recordings (spec 92)
    |--------------------------------------------------------------------------
    |
    | LiveKit Egress records a call (once everyone in it agreed) straight into
    | this S3-compatible bucket — AWS S3, Cloudflare R2, DigitalOcean Spaces,
    | MinIO… LiveKit Cloud has no storage of its own. Keep the bucket private:
    | the app only ever gets a link signed for a few minutes. Without a bucket
    | the "record" button doesn't show. `endpoint` (https://…) is for non-AWS
    | providers, which usually also need `path_style` (R2 uses region `auto`).
    */
    'recordings' => [
        'key' => env('CHAT_RECORDINGS_KEY'),
        'secret' => env('CHAT_RECORDINGS_SECRET'),
        'region' => env('CHAT_RECORDINGS_REGION', 'us-east-1'),
        'bucket' => env('CHAT_RECORDINGS_BUCKET'),
        'endpoint' => env('CHAT_RECORDINGS_ENDPOINT'),
        'path_style' => (bool) env('CHAT_RECORDINGS_PATH_STYLE', false),
        'prefix' => env('CHAT_RECORDINGS_PREFIX', 'call-recordings'),
        // How long everyone has to agree before the request is dropped.
        'consent_seconds' => (int) env('CHAT_RECORDINGS_CONSENT_SECONDS', 30),
        // How long a playback link works.
        'link_seconds' => (int) env('CHAT_RECORDINGS_LINK_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calls ringing timeout
    |--------------------------------------------------------------------------
    |
    | A call nobody answers within this many seconds becomes "missed".
    */
    'call_ring_timeout_seconds' => (int) env('CHAT_CALL_RING_TIMEOUT_SECONDS', 45),

    /*
    |--------------------------------------------------------------------------
    | QR codes
    |--------------------------------------------------------------------------
    |
    | What a chat QR code holds: `dorr://chat/{token}`. The token is random and
    | can be reset by its owner, so a leaked code can be revoked.
    */
    'qr_prefix' => 'dorr://chat/',

    /*
    | Link cards: before fetching a pasted URL the server checks that its host resolves only to
    | public IPs (no localhost / private network). Only tests turn this off.
    */
    'link_preview_check_dns' => env('CHAT_LINK_PREVIEW_CHECK_DNS', true),

    /*
    | GIFs and animated stickers (Giphy). Without a key the GIF / sticker library tabs are empty
    | and only Dorr's own sticker packs show. https://developers.giphy.com
    */
    'giphy' => [
        'key' => env('GIPHY_API_KEY', ''),
        'rating' => env('GIPHY_RATING', 'pg-13'),
    ],
];
