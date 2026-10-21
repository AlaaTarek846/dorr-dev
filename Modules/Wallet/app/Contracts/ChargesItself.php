<?php

namespace Modules\Wallet\Contracts;

/**
 * A purpose that takes the money itself (docs/rides_tasks.md O-67): bus seats, a boat, a driver, a lesson
 * pack. The payment screen applies the coupon and makes sure the wallet holds what's left to pay (or tops it
 * up through a gateway), then calls fulfil() — which debits `payableMinor()` from the wallet in its own
 * words (non-withdrawable money first) and confirms the booking. Nothing is booked as DORR's revenue here:
 * most of it is the captain's, paid out when the service is given.
 */
interface ChargesItself {}
