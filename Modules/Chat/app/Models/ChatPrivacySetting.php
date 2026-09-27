<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\PrivacyAudience;

class ChatPrivacySetting extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'last_seen',
        'profile_photo',
        'read_receipts',
        'who_can_message',
        'who_can_add_to_groups',
        'who_can_call',
        'block_screenshots',
        'notification_preview',
        'qr_token',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen' => PrivacyAudience::class,
            'profile_photo' => PrivacyAudience::class,
            'who_can_message' => PrivacyAudience::class,
            'who_can_add_to_groups' => PrivacyAudience::class,
            'who_can_call' => PrivacyAudience::class,
            'read_receipts' => 'boolean',
            'block_screenshots' => 'boolean',
            'notification_preview' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
