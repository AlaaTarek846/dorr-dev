<?php

namespace Modules\Wallet\Enums;

/**
 * The five ways to get the wallet PIN back, chosen once when the PIN is first created.
 */
enum RecoveryMethod: string
{
    case Password = 'password';
    case BirthDate = 'birth_date';
    case IdPhoto = 'id_photo';
    case PassportPhoto = 'passport_photo';
    case Email = 'email';

    /** Proved by a photo that a person compares in the dashboard (no automatic check). */
    public function isDocument(): bool
    {
        return $this === self::IdPhoto || $this === self::PassportPhoto;
    }

    /** A secret the server can check by itself (hash comparison). */
    public function isSecret(): bool
    {
        return $this === self::Password || $this === self::BirthDate;
    }
}
