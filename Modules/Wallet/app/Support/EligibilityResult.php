<?php

namespace Modules\Wallet\Support;

/**
 * Outcome of a debt-limit check (wallet-plan.md §14).
 *
 * `shortfallMinor` is exactly how much the owner must add to become eligible
 * again ("your balance is X, top up Y to continue"), 0 when eligible.
 */
final class EligibilityResult
{
    public const REASON_SETTINGS_MISSING = 'settings_missing';

    public const REASON_BELOW_LIMIT = 'below_limit';

    public function __construct(
        public readonly bool $eligible,
        public readonly int $balanceMinor,
        public readonly ?int $minAllowedMinor,
        public readonly int $shortfallMinor,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'reason' => $this->reason,
            'balance_minor' => $this->balanceMinor,
            'min_allowed_balance_minor' => $this->minAllowedMinor,
            'shortfall_minor' => $this->shortfallMinor,
        ];
    }
}
