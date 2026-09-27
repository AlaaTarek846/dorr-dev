<?php

namespace Modules\Chat\Enums;

enum ParticipantRole: string
{
    case Member = 'member';
    case Admin = 'admin';
    case Owner = 'owner';

    public function isAdmin(): bool
    {
        return $this !== self::Member;
    }
}
