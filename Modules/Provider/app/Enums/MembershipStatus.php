<?php

namespace Modules\Provider\Enums;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Declined = 'declined';
    case Left = 'left';
    case Removed = 'removed';

    /** Still holds a seat (an open invitation or a working member). */
    public function isOpen(): bool
    {
        return $this === self::Invited || $this === self::Active;
    }
}
