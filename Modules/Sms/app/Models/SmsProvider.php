<?php

namespace Modules\Sms\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;

/**
 * SmsProvider — one row per registered SMS provider adapter (Twilio, SMS Misr).
 *
 * It holds identity (name/key) + status, plus an optional default credential
 * blob in `configuration` (encrypted server-side only). The config SCHEMA is
 * derived live from its adapter (SmsAdapterRegistry), and `configuration` is
 * never exposed to the frontend — the resource only reports which fields are
 * set. Real per-account credentials live on the bound SmsAccount rows (single
 * source of truth for sending).
 */
class SmsProvider extends Model
{
    use SearchFilterTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'configuration',
        'is_active',
        'is_available',
        'last_tested_at',
        'test_status',
        'test_error',
    ];

    /**
     * `encrypted:array` encrypts the blob on write and decrypts on read, so
     * callers always work with a plain array and the column is never plaintext
     * at rest.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'last_tested_at' => 'datetime',
            'configuration' => 'encrypted:array',
        ];
    }

    /**
     * Plaintext provider-level configuration (backend use only).
     *
     * A named alias for the decrypted `configuration` cast: readers can tell
     * at a glance this is never safe to serialize into a response.
     *
     * @return array<string, mixed>
     */
    public function getConfigurationPlaintextAttribute(): array
    {
        return $this->configuration ?? [];
    }

    /**
     * @return HasMany<SmsAccount, $this>
     */
    public function smsAccounts(): HasMany
    {
        return $this->hasMany(SmsAccount::class, 'provider_id');
    }

    public function providerLabel(): string
    {
        return SmsAdapterRegistry::instance()->label($this->key);
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return SmsAdapterRegistry::instance()->capabilities($this->key);
    }

    public function supports(string $capability): bool
    {
        return SmsAdapterRegistry::instance()->supports($this->key, $capability);
    }
}
