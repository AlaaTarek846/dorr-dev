<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A phone (OneSignal player id) that can receive push notifications for an account. An iPhone also
 * has its PushKit `voip_token`: ringing calls go to it through APNs (ApnsVoipPusher).
 */
class NotificationDevice extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'player_id', 'voip_token', 'device_id', 'platform', 'locale', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
