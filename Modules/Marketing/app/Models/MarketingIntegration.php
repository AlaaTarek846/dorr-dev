<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A connected ad account (U10). States (spec 05): PENDING → CONNECTED → EXPIRED / REVOKED / ERROR. The token is kept
 * encrypted and hidden: it never leaves the server, never reaches a prompt or a log (POL-04/05).
 */
class MarketingIntegration extends Model
{
    public const PENDING = 'PENDING';

    public const CONNECTED = 'CONNECTED';

    public const EXPIRED = 'EXPIRED';

    public const REVOKED = 'REVOKED';

    public const ERROR = 'ERROR';

    protected $fillable = [
        'uuid', 'project_id', 'provider', 'external_account_ref', 'external_account_name', 'account_currency', 'scopes', 'credential_ref',
        'token_expires_at', 'status', 'oauth_state_hash', 'oauth_state_expires_at', 'available_accounts', 'sandbox', 'last_error',
        'last_sync_at', 'connected_by_type', 'connected_by_id',
    ];

    protected $hidden = ['credential_ref', 'oauth_state_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $integration) {
            $integration->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'credential_ref' => 'encrypted',
            'scopes' => 'array',
            'available_accounts' => 'array',
            'sandbox' => 'boolean',
            'token_expires_at' => 'datetime',
            'oauth_state_expires_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function usable(): bool
    {
        return $this->status === self::CONNECTED && $this->credential_ref !== null && $this->external_account_ref !== null
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }
}
