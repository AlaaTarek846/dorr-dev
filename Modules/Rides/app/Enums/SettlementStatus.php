<?php

namespace Modules\Rides\Enums;

/** pending until the release date, posted to the wallet, held for a dispute, or reversed. */
enum SettlementStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Held = 'held';
    case Reversed = 'reversed';
}
