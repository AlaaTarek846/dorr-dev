<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use Modules\Wallet\Support\EligibilityResult;
use RuntimeException;

/**
 * The owner's wallet is below the country's debt limit (or the limit isn't
 * configured, which fails closed). Carries the numbers the app needs to say
 * "your balance is X, top up Y to continue".
 */
class NotEligibleException extends RuntimeException implements ApiRenderable
{
    public function __construct(public readonly EligibilityResult $result)
    {
        parent::__construct('Wallet not eligible: '.$result->reason);
    }

    public function apiStatus(): int
    {
        return 403;
    }

    public function apiMessage(): string
    {
        if ($this->result->reason === EligibilityResult::REASON_SETTINGS_MISSING) {
            return __('wallet.errors.eligibility_settings_missing');
        }

        return __('wallet.errors.not_eligible', [
            'balance' => $this->result->balanceMinor / 100,
            'shortfall' => $this->result->shortfallMinor / 100,
        ]);
    }

    public function apiErrorCode(): string
    {
        return 'wallet_not_eligible';
    }

    public function apiData(): array
    {
        return $this->result->toArray();
    }
}
