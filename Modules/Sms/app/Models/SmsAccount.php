<?php

namespace Modules\Sms\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sms\Services\Sms\SmsAvailabilityService;

/**
 * SmsAccount — one usable SMS account bound to a provider. It carries the ACTUAL
 * per-account credentials in `configuration` (encrypted server-side only) and is
 * the single source of truth for them. There is no provider-level fallback.
 *
 * An account is usable only when: provider.is_active = true AND
 * account.is_active = true AND account.test_status = 'passed'.
 *
 * The `configuration` JSON is decrypted for backend use and is NEVER exposed to
 * the frontend.
 */
class SmsAccount extends Model
{
    use SearchFilterTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'provider_id',
        'sender',
        'sender_code',
        'sender_type',
        'configuration',
        'purpose',
        'is_default',
        'is_active',
        'last_tested_at',
        'test_status',
        'test_error',
        'settings',
    ];

    /**
     * The encrypted credential blob must never leak through a raw model
     * serialization — the Resources hand-map every field instead.
     *
     * @var list<string>
     */
    protected $hidden = [
        'configuration',
    ];

    /**
     * `encrypted:array` makes Laravel encrypt on write and decrypt on read, so
     * callers always work with a plain array and the column is never plaintext
     * at rest. Note the SmsAdapterRegistry returns a PREPARED PLAINTEXT array —
     * handing it to a second manual encrypt() here is what used to double-
     * encrypt the blob and break every read.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'array',
            'last_tested_at' => 'datetime',
            'configuration' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<SmsProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(SmsProvider::class, 'provider_id');
    }

    /**
     * Plaintext account-level configuration (backend use only).
     *
     * A named alias for the decrypted `configuration` cast: the Resources and
     * the send pipeline use it so a reader can tell at a glance that this is
     * never safe to serialize into a response.
     *
     * @return array<string, mixed>
     */
    public function getConfigurationPlaintextAttribute(): array
    {
        return $this->configuration ?? [];
    }

    public function isUsable(): bool
    {
        return SmsAvailabilityService::instance()->accountReady($this);
    }
}
