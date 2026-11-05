<?php

namespace Modules\Provider\Enums;

/** Nothing reaches an establishment (no requests, no offers) until the admin approves it (REQ-008). */
enum EstablishmentStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
