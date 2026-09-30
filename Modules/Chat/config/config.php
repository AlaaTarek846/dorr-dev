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
