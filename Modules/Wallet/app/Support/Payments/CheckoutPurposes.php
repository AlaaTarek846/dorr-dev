<?php

namespace Modules\Wallet\Support\Payments;

use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Exceptions\CheckoutException;

/**
 * The registry of things the payment screen sells: `key => CheckoutPurpose class`. Bound as a
 * singleton; modules add theirs from their service provider's boot().
 */
class CheckoutPurposes
{
    /** @var array<string, class-string<CheckoutPurpose>> */
    private array $purposes = [];

    /**
     * @param  class-string<CheckoutPurpose>  $class
     */
    public function register(string $key, string $class): void
    {
        $this->purposes[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->purposes[$key]);
    }

    public function for(string $key): CheckoutPurpose
    {
        $class = $this->purposes[$key] ?? throw CheckoutException::unknownPurpose();

        return app($class);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->purposes);
    }
}
