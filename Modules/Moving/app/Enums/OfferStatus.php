<?php

namespace Modules\Moving\Enums;

enum OfferStatus: string
{
    case AwaitingApproval = 'awaiting_approval';    // above the sender's limit, waiting for someone with offers.approve (§17)
    case Sent = 'sent';
    case Withdrawn = 'withdrawn';
    case Accepted = 'accepted';
    case Declined = 'declined';                     // the customer chose another one
    case Expired = 'expired';
}
