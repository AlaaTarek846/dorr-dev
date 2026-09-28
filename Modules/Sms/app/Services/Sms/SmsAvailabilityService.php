<?php

namespace Modules\Sms\Services\Sms;

use Modules\Sms\Models\SmsAccount;

/**
 * SmsAvailabilityService — the single source of truth that decides whether SMS
 * sending may be shown/used anywhere in the CRM.
 *
 * SMS is "available" only when ALL of the following hold:
 *   1. At least one SMS Provider exists and is active.
 *   2. That provider has at least one account that is active.
 *   3. That account's connection test passed.
 *
 * The usable account therefore requires: provider.is_active = true AND
 * account.is_active = true AND account.test_status = 'passed'.
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
        return $this->availableAccount() !== null;
    }

    /**
     * True when an active, tested account backed by an active provider exists.
     */
    public function hasActiveAccount(): bool
    {
        return $this->availableAccount() !== null;
    }

    /**
     * The active, tested account whose provider is also active — or null.
     * Prefers the default account, then the newest.
     */
    public function availableAccount(): ?SmsAccount
    {
        return SmsAccount::query()
            ->where('is_active', true)
            ->where('test_status', 'passed')
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->orderBy('is_default', 'desc')
            ->latest('id')
            ->first();
    }

    /**
     * True when the given account is usable: account active + test passed +
     * its provider active.
     */
    public function accountReady(SmsAccount $account): bool
    {
        return $account->is_active
            && $account->test_status === 'passed'
            && $account->provider?->is_active === true;
    }

    /**
     * The provider instances registry (metadata for building forms).
     */
    public function providerService(): SmsAdapterRegistry
    {
        return SmsAdapterRegistry::instance();
    }
}
