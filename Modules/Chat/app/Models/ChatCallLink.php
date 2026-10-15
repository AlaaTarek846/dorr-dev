<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\CallType;

/**
 * A call link (WhatsApp list 72) — see CallLinkService.
 *
 * @property string $token
 * @property string $owner_type
 * @property int $owner_id
 * @property CallType $type
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $last_joined_at
 */
class ChatCallLink extends Model
{
    protected $table = 'chat_call_links';

    protected $fillable = ['token', 'owner_type', 'owner_id', 'type', 'expires_at', 'revoked_at', 'last_joined_at'];

    protected function casts(): array
    {
        return [
            'type' => CallType::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_joined_at' => 'datetime',
        ];
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** The LiveKit room behind the link. */
    public function roomName(): string
    {
        return 'link-'.$this->token;
    }
}
