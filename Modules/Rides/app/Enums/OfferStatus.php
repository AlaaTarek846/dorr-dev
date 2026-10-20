<?php

namespace Modules\Rides\Enums;

/** A ride offered to one driver in a dispatch round. */
enum OfferStatus: string
{
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
}
