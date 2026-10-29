<?php

namespace Modules\Chat\Enums;

/**
 * The badge next to an account's name (REQ-02-01) — its own life, apart from the request's and the
 * subscription's. "Not verified" is no badge row (or one that is expired / withdrawn).
 *
 *   temporary (early, the request still open) ─▶ active ─▶ expired ─▶ active (renewed)
 *   temporary / active ─▶ suspended ─▶ back where it was
 *   any ─▶ withdrawn (after a review, with its reason)
 */
enum VerificationBadgeStatus: string
{
    case Temporary = 'temporary';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    /** The ✔ shows. */
    public function shows(): bool
    {
        return $this === self::Temporary || $this === self::Active;
    }

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Temporary => [self::Active, self::Suspended, self::Expired, self::Withdrawn],
            self::Active => [self::Suspended, self::Expired, self::Withdrawn],
            self::Suspended => [self::Temporary, self::Active, self::Withdrawn, self::Expired],
            self::Expired, self::Withdrawn => [self::Temporary, self::Active],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
