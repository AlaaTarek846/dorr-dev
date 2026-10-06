<?php

namespace Modules\Wallet\Contracts;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * Something the payment screen can sell. A module registers one per kind of thing it sells
 * (CheckoutPurposes::register) — the wallet never knows what a portal or a verification is.
 */
interface CheckoutPurpose
{
    /**
     * What it costs this owner in this country, and how it reads on the payment screen. Throws
     * (any ApiRenderable) when it can't be bought — not theirs, unknown package, no price here…
     *
     * @param  array<string, mixed>  $reference  as the app sent it
     */
    public function line(Model $owner, Country $country, array $reference): CheckoutLine;

    /**
     * Deliver it — runs inside the same database transaction as the payment, exactly once.
     */
    public function fulfil(Checkout $checkout): void;
}
