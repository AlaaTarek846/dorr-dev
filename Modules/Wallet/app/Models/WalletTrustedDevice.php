<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A device that already proved it can reach the phone number on file — see DeviceTrustService.
 * `device_id` is a random id the app generates once and keeps locally, not a hardware identifier.
 */
class WalletTrustedDevice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['owner_type', 'owner_id', 'device_id', 'trusted_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
