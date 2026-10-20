<?php

namespace Modules\Wallet\Contracts;

/**
 * A CheckoutPurpose that keeps the money in the wallet and holds it itself (a ride: the final
 * price is known only at the end, and part of it is the driver's). Paying its checkout only makes
 * sure the money is there — from the balance or a gateway top-up — then calls fulfil(), which
 * places the hold. Nothing is debited and nothing is booked as revenue here; no coupons.
 */
interface ReservesFunds {}
