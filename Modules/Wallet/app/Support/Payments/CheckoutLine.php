<?php

namespace Modules\Wallet\Support\Payments;

/**
 * A purpose's answer for one checkout: the price (in the country's currency) and the words on
 * the payment screen. `reference` is what gets stored — the purpose's own clean version of it.
 */
final class CheckoutLine
{
    /**
     * @param  array<string, mixed>  $reference
     */
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly array $reference,
    ) {}
}
