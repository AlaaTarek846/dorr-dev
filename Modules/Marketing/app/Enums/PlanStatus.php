<?php

namespace Modules\Marketing\Enums;

enum PlanStatus: string
{
    case Draft = 'DRAFT';
    case ReadyForReview = 'READY_FOR_REVIEW';
    case Approved = 'APPROVED';
    case Superseded = 'SUPERSEDED';
    case Archived = 'ARCHIVED';
}
