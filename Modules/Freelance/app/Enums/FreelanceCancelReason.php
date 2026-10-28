<?php

namespace Modules\Freelance\Enums;

/**
 * Why a project or a part of it was called off (FL-607), as the one who called it off stated it, and who that points at.
 * The classification is for staff only: nothing is charged, refused or changed because of it.
 */
enum FreelanceCancelReason: string
{
    case ScopeChanged = 'scope_changed';
    case ClientUnresponsive = 'client_unresponsive';
    case NotDelivered = 'not_delivered';
    case Quality = 'quality';
    case Mutual = 'mutual';
    case ForceMajeure = 'force_majeure';
    case Other = 'other';

    public const CLIENT = 'client';

    public const FREELANCER = 'freelancer';

    public const SHARED = 'shared';

    public const NONE = 'none';

    public const REVIEW = 'review';

    /** @return 'client'|'freelancer'|'shared'|'none'|'review' */
    public function responsibility(): string
    {
        return match ($this) {
            self::ScopeChanged, self::ClientUnresponsive => self::CLIENT,
            self::NotDelivered, self::Quality => self::FREELANCER,
            self::Mutual => self::SHARED,
            self::ForceMajeure => self::NONE,
            self::Other => self::REVIEW,
        };
    }

    /** @return list<string> */
    public static function responsibilities(): array
    {
        return [self::CLIENT, self::FREELANCER, self::SHARED, self::NONE, self::REVIEW];
    }
}
