<?php

namespace Modules\Sms\Services\Sms;

use Illuminate\Support\Facades\Log;
use Modules\Sms\Models\SmsProvider;

/**
 * SmsAvailabilityService — decides whether SMS sending may be shown/used
 * anywhere in the CRM.
 *
 * SMS is "available" only when an active, configured, tested provider exists.
 *
 * isAvailable() performs NO permission checks — callers combine it with
 * authorization (e.g. "sms send" permission).
 */
class SmsAvailabilityService
{
    public static function instance(): self
    {
        return new static;
    }

    /**
     * Is SMS generally usable in the CRM?
     */
    public function isAvailable(): bool
    {
        return $this->availableProvider() !== null;
    }

    /**
     * True when an active, configured, tested provider backed by an active
     * provider exists.
     */
    public function hasActiveProvider(): bool
    {
        return $this->availableProvider() !== null;
    }

    /**
     * The active, tested provider whose configuration and test passed —
     * or null. Prefers lower priority, then newest.
     *
     * @return SmsProvider|null
     */
    public function availableProvider(): ?SmsProvider
    {
        return SmsProvider::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->where('test_status', 'passed')
            ->whereNotNull('configuration')
            ->orderBy('priority', 'asc')
            ->latest('id')
            ->first();
    }

    /**
     * True when the given provider is ready: active + available + test
     * passed + configuration present.
     */
    public function providerReady(SmsProvider $provider): bool
    {
        return $provider->is_active
            && $provider->is_available
            && $provider->test_status === 'passed'
            && ! empty($provider->configuration_plaintext);
    }

    /**
     * The provider instances registry (metadata for building forms).
     */
    public function providerService(): SmsAdapterRegistry
    {
        return SmsAdapterRegistry::instance();
    }
}
